<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\CreditEntryType;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\models\CreditEntry;
use justinholtweb\boomerang\models\CreditLot;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\records\CreditEntryRecord;
use justinholtweb\boomerang\records\CreditLotRecord;
use RuntimeException;
use yii\db\IntegrityException;

/**
 * The store-credit wallet.
 *
 * **The invariant: `post()` is the only place a ledger row is written.** Issue, hold, capture,
 * spend, refund, expire, adjust and revoke all end there, so the lot's `amountRemaining` and the
 * entry that changed it are written in the same statement pair, under the same lock, or not at
 * all.
 *
 * ## Lots, and why
 *
 * Credit expires, and a single running balance cannot answer "how much of this customer's $60
 * lapses at the end of the month" without re-deriving which dollars came from which issue.
 * Getting that wrong means either expiring money the customer still owns or letting them spend
 * money that lapsed. So credit is issued in **lots**, each knowing what it was worth, what is left
 * and when it dies, and spending consumes them in expiry order — the money about to lapse is
 * always the money spent first, which is both correct and what a customer would choose.
 *
 * ## Concurrency
 *
 * A wallet is the one place in a store where two requests spending the same money is a real bug
 * rather than a hypothetical one — a customer with two tabs open, a double-submitted checkout, a
 * retried webhook. Allocation therefore takes `SELECT … FOR UPDATE` on the customer's lots inside
 * a transaction, and every caller supplies an `idempotencyKey` that is unique across the ledger.
 * The lock stops two concurrent spends; the key stops the same spend being replayed.
 */
class Wallet extends Component
{
    /**
     * What the customer can spend right now.
     *
     * Expiry is applied here as well as by `boomerang/credit/expire`, so an unrun cron cannot let
     * expired credit be spent. The console command exists to write the ledger entries that explain
     * where the money went, not to enforce the rule.
     */
    public function getBalance(int $customerId, ?int $storeId = null, ?string $currency = null): float
    {
        $storeId ??= $this->defaultStoreId();
        $currency ??= $this->defaultCurrency($storeId);

        $total = (new Query())
            ->from([Table::CREDITLOTS])
            ->where([
                'customerId' => $customerId,
                'storeId' => $storeId,
                'currency' => $currency,
            ])
            ->andWhere(['>', 'amountRemaining', 0])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', Db::prepareDateForDb(new DateTime())]])
            ->sum('[[amountRemaining]]');

        return round((float)$total, 2);
    }

    /**
     * What the customer holds that is going to lapse within `$days`.
     *
     * The number a "your credit expires soon" email is built on.
     */
    public function getExpiringBalance(int $customerId, int $days, ?int $storeId = null, ?string $currency = null): float
    {
        $storeId ??= $this->defaultStoreId();
        $currency ??= $this->defaultCurrency($storeId);

        $cutoff = (new DateTime())->add(new DateInterval('P' . max(0, $days) . 'D'));

        $total = (new Query())
            ->from([Table::CREDITLOTS])
            ->where([
                'customerId' => $customerId,
                'storeId' => $storeId,
                'currency' => $currency,
            ])
            ->andWhere(['>', 'amountRemaining', 0])
            ->andWhere(['not', ['expiresAt' => null]])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->andWhere(['<=', 'expiresAt', Db::prepareDateForDb($cutoff)])
            ->sum('[[amountRemaining]]');

        return round((float)$total, 2);
    }

    /**
     * @return CreditLot[] Spendable first, in the order they would be spent.
     */
    public function getLots(int $customerId, ?int $storeId = null, ?string $currency = null, bool $includeEmpty = false): array
    {
        $storeId ??= $this->defaultStoreId();
        $currency ??= $this->defaultCurrency($storeId);

        $query = (new Query())
            ->from([Table::CREDITLOTS])
            ->where(['customerId' => $customerId, 'storeId' => $storeId, 'currency' => $currency])
            ->orderBy([
                'expiresAt' => SORT_ASC,
                'dateCreated' => SORT_ASC,
                'id' => SORT_ASC,
            ]);

        if (!$includeEmpty) {
            $query->andWhere(['>', 'amountRemaining', 0]);
        }

        return array_map(fn(array $row) => $this->createLot($row), $query->all());
    }

    public function getLotById(int $id): ?CreditLot
    {
        $row = (new Query())->from([Table::CREDITLOTS])->where(['id' => $id])->one();

        return $row ? $this->createLot($row) : null;
    }

    /**
     * @return CreditEntry[] Newest first.
     */
    public function getEntries(int $customerId, ?int $storeId = null, ?int $limit = null): array
    {
        $query = (new Query())
            ->from([Table::CREDITENTRIES])
            ->where(['customerId' => $customerId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($storeId !== null) {
            $query->andWhere(['storeId' => $storeId]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map(fn(array $row) => $this->createEntry($row), $query->all());
    }

    // Issuing
    // -------------------------------------------------------------------------

    /**
     * Create a lot and the entry that opened it.
     *
     * Options: `expiresAt`, `expiryDays`, `returnId`, `note`, `issuedBy`, `idempotencyKey`,
     * `storeId`, `currency`.
     */
    public function issue(int $customerId, float $amount, array $options = []): CreditLot
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException(Craft::t('boomerang', 'Store credit must be worth something.'));
        }

        $storeId = $options['storeId'] ?? $this->defaultStoreId();
        $currency = $options['currency'] ?? $this->defaultCurrency($storeId);

        $expiresAt = $options['expiresAt'] ?? null;

        if ($expiresAt === null) {
            $days = (int)($options['expiryDays'] ?? Plugin::getInstance()->getSettings()->creditExpiryDays);

            if ($days > 0) {
                $expiresAt = (new DateTime())->add(new DateInterval('P' . $days . 'D'));
            }
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record = new CreditLotRecord();
            $record->customerId = $customerId;
            $record->storeId = $storeId;
            $record->currency = $currency;
            $record->amountIssued = $amount;
            $record->amountRemaining = $amount;
            $record->expiresAt = $expiresAt ? Db::prepareDateForDb($expiresAt) : null;
            $record->returnId = $options['returnId'] ?? null;
            $record->issuedBy = $options['issuedBy'] ?? Craft::$app->getUser()->getIdentity()?->id;
            $record->note = $options['note'] ?? null;
            $record->save(false);

            $lot = $this->getLotById((int)$record->id);

            $this->post(new CreditEntry([
                'lotId' => (int)$record->id,
                'customerId' => $customerId,
                'storeId' => $storeId,
                'currency' => $currency,
                'type' => CreditEntryType::Issue->value,
                'amount' => $amount,
                'returnId' => $options['returnId'] ?? null,
                'idempotencyKey' => $options['idempotencyKey'] ?? sprintf('issue:lot:%d', $record->id),
                'note' => $options['note'] ?? null,
            ]), adjustLot: false);

            $transaction->commit();

            return $lot;
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Issue the credit an RMA resolved to, with any bonus applied.
     *
     * The bonus is the lever that makes credit the resolution customers pick — "$50 back, or $55
     * to spend here" — and it is applied here rather than in the amount calculation so that the
     * RMA keeps saying what the goods were worth.
     */
    public function issueForReturn(ReturnRequest $return): CreditLot
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->getEffectiveCreditEnabled()) {
            throw new RuntimeException(Craft::t('boomerang', 'Store credit is not enabled.'));
        }

        if ($return->customerId === null) {
            throw new RuntimeException(Craft::t('boomerang', 'Store credit needs a customer account. This order was placed as a guest.'));
        }

        $bonus = $settings->getEffectiveCreditBonusPercent();
        $amount = round($return->resolutionAmount * (1 + $bonus / 100), 2);

        $lot = $this->issue($return->customerId, $amount, [
            'storeId' => $return->storeId ?? $this->defaultStoreId(),
            'currency' => $return->currency,
            'returnId' => $return->id,
            'note' => Craft::t('boomerang', 'Return {reference}', ['reference' => $return->reference]),
            // One lot per RMA. A retried transition finds the key taken and leaves the money alone.
            'idempotencyKey' => sprintf('return:%d:credit', $return->id),
        ]);

        $return->creditedAmount = $amount;

        Plugin::getInstance()->returns->addEvent($return, EventType::CreditIssued, null, [
            'amount' => $amount,
            'bonusPercent' => $bonus,
            'lotId' => $lot->id,
            'expiresAt' => $lot->expiresAt?->format(DATE_ATOM),
        ]);

        return $lot;
    }

    // Spending
    // -------------------------------------------------------------------------

    /**
     * Allocate credit against an order, as a reversible hold.
     *
     * @return CreditEntry[]
     */
    public function hold(int $customerId, float $amount, string $idempotencyKey, array $context = []): array
    {
        return $this->allocate($customerId, $amount, CreditEntryType::Hold, $idempotencyKey, $context);
    }

    /**
     * Allocate credit against an order and keep it.
     *
     * @return CreditEntry[]
     */
    public function spend(int $customerId, float $amount, string $idempotencyKey, array $context = []): array
    {
        return $this->allocate($customerId, $amount, CreditEntryType::Spend, $idempotencyKey, $context);
    }

    /**
     * Turn a hold into a spend.
     *
     * The rows are retyped rather than reversed and re-drawn: the money never moved, and a capture
     * that re-allocated could land on different lots than the authorization did — which is how a
     * customer ends up having spent credit that expired between the two.
     *
     * @return int The number of entries captured.
     */
    public function captureHolds(string $transactionHash): int
    {
        return Craft::$app->getDb()->createCommand()
            ->update(
                Table::CREDITENTRIES,
                ['type' => CreditEntryType::Spend->value],
                ['transactionHash' => $transactionHash, 'type' => CreditEntryType::Hold->value],
            )
            ->execute();
    }

    /**
     * Give back an authorization that was never captured.
     *
     * @return float The amount released.
     */
    public function releaseHolds(string $transactionHash): float
    {
        $entries = $this->entriesByHash($transactionHash, CreditEntryType::Hold);
        $released = 0.0;

        foreach ($entries as $entry) {
            $this->post(new CreditEntry([
                'lotId' => $entry->lotId,
                'customerId' => $entry->customerId,
                'storeId' => $entry->storeId,
                'currency' => $entry->currency,
                'type' => CreditEntryType::ReleaseHold->value,
                'amount' => abs($entry->amount),
                'orderId' => $entry->orderId,
                'transactionHash' => $transactionHash,
                'idempotencyKey' => sprintf('release:%d', $entry->id),
            ]));

            $released += abs($entry->amount);
        }

        return round($released, 2);
    }

    /**
     * Put a wallet payment back where it came from.
     *
     * Back into the exact lots it was drawn from, so a refund does not quietly extend the life of
     * money that was about to lapse — unless a lot has expired in the meantime, in which case a
     * fresh lot is issued. Refunding a customer into money they cannot spend is not a refund.
     *
     * @return float The amount actually returned.
     */
    public function refundToWallet(string $transactionHash, ?float $amount = null, array $context = []): float
    {
        $entries = $this->entriesByHash($transactionHash, CreditEntryType::Spend);

        if ($entries === []) {
            return 0.0;
        }

        $alreadyRefunded = 0.0;

        foreach ($this->entriesByHash($transactionHash, CreditEntryType::Refund) as $refund) {
            $alreadyRefunded += abs($refund->amount);
        }

        $spent = 0.0;

        foreach ($entries as $entry) {
            $spent += abs($entry->amount);
        }

        $remaining = round($spent - $alreadyRefunded, 2);
        $target = $amount === null ? $remaining : round(min($amount, $remaining), 2);

        if ($target <= 0) {
            return 0.0;
        }

        $now = new DateTime();
        $returned = 0.0;
        $sequence = 0;

        foreach ($entries as $entry) {
            if ($target <= 0) {
                break;
            }

            $portion = round(min(abs($entry->amount), $target), 2);
            $sequence++;

            $lot = $entry->lotId !== null ? $this->getLotById($entry->lotId) : null;

            if ($lot !== null && !$lot->getIsExpired($now)) {
                $this->post(new CreditEntry([
                    'lotId' => $lot->id,
                    'customerId' => $entry->customerId,
                    'storeId' => $entry->storeId,
                    'currency' => $entry->currency,
                    'type' => CreditEntryType::Refund->value,
                    'amount' => $portion,
                    'orderId' => $context['orderId'] ?? $entry->orderId,
                    'transactionHash' => $transactionHash,
                    'transactionId' => $context['transactionId'] ?? null,
                    'idempotencyKey' => sprintf('refund:%s:%d', $transactionHash, $sequence),
                    'note' => $context['note'] ?? null,
                ]));
            } else {
                // The lot it came from has lapsed. A new one, on the store's current expiry terms.
                $this->issue((int)$entry->customerId, $portion, [
                    'storeId' => $entry->storeId,
                    'currency' => $entry->currency,
                    'note' => Craft::t('boomerang', 'Refund of expired store credit'),
                    'idempotencyKey' => sprintf('refund:%s:%d:reissue', $transactionHash, $sequence),
                ]);
            }

            $returned += $portion;
            $target -= $portion;
        }

        return round($returned, 2);
    }

    /**
     * Take credit back — a fraudulent return, a reversed decision.
     *
     * @return float The amount actually revoked, which can be less than asked for if the customer
     *               has already spent it. Credit is not a debt: a wallet never goes negative.
     */
    public function revoke(int $customerId, float $amount, string $idempotencyKey, array $context = []): float
    {
        $entries = $this->allocate($customerId, $amount, CreditEntryType::Revoke, $idempotencyKey, $context, allowPartial: true);
        $revoked = 0.0;

        foreach ($entries as $entry) {
            $revoked += abs($entry->amount);
        }

        return round($revoked, 2);
    }

    /**
     * Write off everything that has lapsed, so the ledger explains the balance it reports.
     *
     * @return array{lots: int, amount: float}
     */
    public function expire(?DateTime $now = null): array
    {
        $now ??= new DateTime();

        $rows = (new Query())
            ->from([Table::CREDITLOTS])
            ->where(['>', 'amountRemaining', 0])
            ->andWhere(['not', ['expiresAt' => null]])
            ->andWhere(['<=', 'expiresAt', Db::prepareDateForDb($now)])
            ->all();

        $amount = 0.0;

        foreach ($rows as $row) {
            $lot = $this->createLot($row);

            $this->post(new CreditEntry([
                'lotId' => $lot->id,
                'customerId' => $lot->customerId,
                'storeId' => $lot->storeId,
                'currency' => $lot->currency,
                'type' => CreditEntryType::Expire->value,
                'amount' => -$lot->amountRemaining,
                'idempotencyKey' => sprintf('expire:lot:%d', $lot->id),
                'note' => Craft::t('boomerang', 'Expired {date}', [
                    'date' => $lot->expiresAt?->format('Y-m-d'),
                ]),
            ]));

            $amount += $lot->amountRemaining;
        }

        return ['lots' => count($rows), 'amount' => round($amount, 2)];
    }

    // The writer
    // -------------------------------------------------------------------------

    /**
     * Write one ledger row and move its lot.
     *
     * The only writer. `adjustLot` is false exactly once — when opening a lot, whose
     * `amountRemaining` is set by the insert itself.
     *
     * A duplicate `idempotencyKey` is not an error: it means this movement has already happened,
     * and the existing entry is returned. That is what makes a retried checkout safe.
     */
    public function post(CreditEntry $entry, bool $adjustLot = true): CreditEntry
    {
        $db = Craft::$app->getDb();
        $ownTransaction = $db->getTransaction() === null;
        $transaction = $ownTransaction ? $db->beginTransaction() : null;

        try {
            $record = new CreditEntryRecord();
            $record->lotId = $entry->lotId;
            $record->customerId = $entry->customerId;
            $record->storeId = $entry->storeId;
            $record->currency = $entry->currency;
            $record->type = $entry->type;
            $record->amount = round($entry->amount, 2);
            $record->orderId = $entry->orderId;
            $record->transactionId = $entry->transactionId;
            $record->transactionHash = $entry->transactionHash;
            $record->returnId = $entry->returnId;
            $record->userId = $entry->userId ?? Craft::$app->getUser()->getIdentity()?->id;
            $record->idempotencyKey = $entry->idempotencyKey;
            $record->note = $entry->note;

            try {
                $record->save(false);
            } catch (IntegrityException) {
                $transaction?->rollBack();

                $existing = $this->getEntryByKey($entry->idempotencyKey);

                if ($existing === null) {
                    throw new RuntimeException(Craft::t('boomerang', 'Could not write the store credit entry.'));
                }

                return $existing;
            }

            if ($adjustLot && $entry->lotId !== null) {
                // Written as a relative update rather than as a read-modify-write, so that two
                // entries against the same lot inside one request cannot lose each other.
                $db->createCommand()
                    ->update(
                        Table::CREDITLOTS,
                        ['amountRemaining' => new \yii\db\Expression('[[amountRemaining]] + :delta', [':delta' => round($entry->amount, 2)])],
                        ['id' => $entry->lotId],
                    )
                    ->execute();
            }

            $transaction?->commit();

            $entry->id = (int)$record->id;
            $entry->dateCreated = \craft\helpers\DateTimeHelper::toDateTime($record->dateCreated) ?: null;

            return $entry;
        } catch (\Throwable $e) {
            $transaction?->rollBack();

            throw $e;
        }
    }

    public function getEntryByKey(string $idempotencyKey): ?CreditEntry
    {
        $row = (new Query())
            ->from([Table::CREDITENTRIES])
            ->where(['idempotencyKey' => $idempotencyKey])
            ->one();

        return $row ? $this->createEntry($row) : null;
    }

    // Allocation
    // -------------------------------------------------------------------------

    /**
     * Draw an amount out of the customer's lots, oldest expiry first.
     *
     * Takes a row lock on the lots for the duration, because this is where a double spend would
     * otherwise happen. Refuses outright rather than partially unless `$allowPartial`: a checkout
     * that quietly paid $30 of a $40 request would leave an order half-paid with no error anybody
     * could see.
     *
     * @return CreditEntry[]
     */
    private function allocate(
        int $customerId,
        float $amount,
        CreditEntryType $type,
        string $idempotencyKey,
        array $context = [],
        bool $allowPartial = false,
    ): array {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return [];
        }

        // Already done. Returning the original entries makes a replayed request a no-op rather
        // than a second charge.
        $existing = $this->entriesByKeyPrefix($idempotencyKey);

        if ($existing !== []) {
            return $existing;
        }

        $storeId = $context['storeId'] ?? $this->defaultStoreId();
        $currency = $context['currency'] ?? $this->defaultCurrency($storeId);

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $lots = $this->lockedLots($customerId, $storeId, $currency);
            $available = 0.0;

            foreach ($lots as $lot) {
                $available += (float)$lot['amountRemaining'];
            }

            if (!$allowPartial && round($available, 2) < $amount) {
                throw new RuntimeException(Craft::t('boomerang', 'Not enough store credit: {balance} available, {amount} needed.', [
                    'balance' => $available,
                    'amount' => $amount,
                ]));
            }

            $remaining = $allowPartial ? min($amount, round($available, 2)) : $amount;
            $entries = [];
            $sequence = 0;

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $portion = round(min((float)$lot['amountRemaining'], $remaining), 2);

                if ($portion <= 0) {
                    continue;
                }

                $sequence++;

                $entries[] = $this->post(new CreditEntry([
                    'lotId' => (int)$lot['id'],
                    'customerId' => $customerId,
                    'storeId' => $storeId,
                    'currency' => $currency,
                    'type' => $type->value,
                    'amount' => -$portion,
                    'orderId' => $context['orderId'] ?? null,
                    'transactionId' => $context['transactionId'] ?? null,
                    'transactionHash' => $context['transactionHash'] ?? null,
                    'returnId' => $context['returnId'] ?? null,
                    // Suffixed per lot: one logical spend can touch several lots, and each row
                    // needs its own unique key.
                    'idempotencyKey' => sprintf('%s#%d', $idempotencyKey, $sequence),
                    'note' => $context['note'] ?? null,
                ]));

                $remaining = round($remaining - $portion, 2);
            }

            $transaction->commit();

            return $entries;
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * The customer's spendable lots, locked for update, in the order they should be consumed.
     *
     * Raw SQL because neither Yii nor Craft's query builder can express `FOR UPDATE`, and this is
     * the one query in the plugin where the lock is the point.
     *
     * @return array<int, array{id: int, amountRemaining: string}>
     */
    private function lockedLots(int $customerId, ?int $storeId, string $currency): array
    {
        $db = Craft::$app->getDb();
        $table = $db->quoteTableName($db->getSchema()->getRawTableName(Table::CREDITLOTS));

        $col = fn(string $name) => $db->quoteColumnName($name);

        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s = :customerId AND %s %s AND %s = :currency AND %s > 0 AND (%s IS NULL OR %s > :now) ORDER BY CASE WHEN %s IS NULL THEN 1 ELSE 0 END ASC, %s ASC, %s ASC, %s ASC FOR UPDATE',
            $col('id'),
            $col('amountRemaining'),
            $table,
            $col('customerId'),
            $col('storeId'),
            $storeId === null ? 'IS NULL' : '= :storeId',
            $col('currency'),
            $col('amountRemaining'),
            $col('expiresAt'),
            $col('expiresAt'),
            $col('expiresAt'),
            $col('expiresAt'),
            $col('dateCreated'),
            $col('id'),
        );

        $params = [
            ':customerId' => $customerId,
            ':currency' => $currency,
            ':now' => Db::prepareDateForDb(new DateTime()),
        ];

        if ($storeId !== null) {
            $params[':storeId'] = $storeId;
        }

        return $db->createCommand($sql, $params)->queryAll();
    }

    /**
     * @return CreditEntry[]
     */
    private function entriesByKeyPrefix(string $prefix): array
    {
        $rows = (new Query())
            ->from([Table::CREDITENTRIES])
            ->where(['like', 'idempotencyKey', $prefix . '#', false])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createEntry($row), $rows);
    }

    /**
     * @return CreditEntry[]
     */
    private function entriesByHash(string $transactionHash, CreditEntryType $type): array
    {
        $rows = (new Query())
            ->from([Table::CREDITENTRIES])
            ->where(['transactionHash' => $transactionHash, 'type' => $type->value])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createEntry($row), $rows);
    }

    // Plumbing
    // -------------------------------------------------------------------------

    public function defaultStoreId(): ?int
    {
        return Commerce::getInstance()?->getStores()->getPrimaryStore()->id;
    }

    public function defaultCurrency(?int $storeId = null): string
    {
        $stores = Commerce::getInstance()?->getStores();
        $store = $storeId !== null ? $stores?->getStoreById($storeId) : $stores?->getPrimaryStore();

        // `Store::getCurrency()` returns a Money\Currency object, not an ISO code — passing it
        // anywhere that wants a string throws.
        return $store?->getCurrency()->getCode() ?? 'USD';
    }

    private function createLot(array $row): CreditLot
    {
        $row['id'] = (int)$row['id'];
        $row['customerId'] = (int)$row['customerId'];
        $row['storeId'] = $row['storeId'] !== null ? (int)$row['storeId'] : null;
        $row['amountIssued'] = (float)$row['amountIssued'];
        $row['amountRemaining'] = (float)$row['amountRemaining'];

        return new CreditLot($row);
    }

    private function createEntry(array $row): CreditEntry
    {
        $row['id'] = (int)$row['id'];
        $row['lotId'] = $row['lotId'] !== null ? (int)$row['lotId'] : null;
        $row['customerId'] = (int)$row['customerId'];
        $row['amount'] = (float)$row['amount'];

        return new CreditEntry($row);
    }
}
