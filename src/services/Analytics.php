<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\Plugin;

/**
 * What the returns are telling you.
 *
 * The point of recording a reason on every line is that somebody eventually asks which product
 * keeps coming back and why — and the answer is only useful split by fault. A 12% return rate that
 * is all "bought two sizes" is a sizing chart problem; the same 12% that is all "arrived damaged"
 * is a packing problem. Every report here keeps the two apart.
 *
 * Everything is a single grouped query against the sub-tables. None of it hydrates elements: a
 * store with 40,000 returns should still get a report.
 */
class Analytics extends Component
{
    /**
     * Headline numbers for a period.
     *
     * @return array<string, mixed>
     */
    public function summary(?DateTime $from = null, ?DateTime $to = null): array
    {
        $rows = $this->baseQuery($from, $to)
            ->select([
                'total' => 'COUNT(*)',
                'value' => 'SUM([[br.resolutionAmount]])',
                'refunded' => 'SUM([[br.refundedAmount]])',
                'credited' => 'SUM([[br.creditedAmount]])',
                'fees' => 'SUM([[br.feeAmount]])',
            ])
            ->one();

        // Built by filtering rather than with array_diff(), which stringifies its operands and
        // fatals on enum cases.
        $openKinds = array_values(array_filter(
            StateKind::cases(),
            static fn(StateKind $kind) => !$kind->isClosed(),
        ));

        return [
            'total' => (int)($rows['total'] ?? 0),
            'value' => round((float)($rows['value'] ?? 0), 2),
            'refunded' => round((float)($rows['refunded'] ?? 0), 2),
            'credited' => round((float)($rows['credited'] ?? 0), 2),
            'fees' => round((float)($rows['fees'] ?? 0), 2),
            'open' => $this->countByKinds($from, $to, $openKinds),
            'resolved' => $this->countByKinds($from, $to, [StateKind::Resolved]),
            'rejected' => $this->countByKinds($from, $to, [StateKind::Rejected]),
            'faultRate' => $this->faultRate($from, $to),
            'medianDaysToResolve' => $this->medianDaysToResolve($from, $to),
            'returnRate' => $this->returnRate($from, $to),
            'restockRecovery' => $this->restockRecovery($from, $to),
        ];
    }

    /**
     * Why things came back, by count and by value.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byReason(?DateTime $from = null, ?DateTime $to = null): array
    {
        $rows = $this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->leftJoin(['brr' => Table::REASONS], '[[brr.id]] = [[bri.reasonId]]')
            ->select([
                'reasonId' => 'bri.reasonId',
                // The copied name, so a reason somebody deleted still has a row rather than
                // collapsing into a nameless bucket.
                'name' => 'COALESCE([[brr.name]], [[bri.reasonName]])',
                'isFault' => 'MAX([[brr.isFault]])',
                'returns' => 'COUNT(DISTINCT [[br.id]])',
                'units' => 'SUM([[bri.qtyRequested]])',
                'value' => 'SUM([[bri.qtyRequested]] * ([[bri.unitPrice]] + [[bri.unitTax]]))',
            ])
            ->groupBy(['bri.reasonId', 'brr.name', 'bri.reasonName'])
            ->orderBy(['units' => SORT_DESC])
            ->all();

        return array_map(fn(array $row) => [
            'reasonId' => $row['reasonId'] !== null ? (int)$row['reasonId'] : null,
            'name' => (string)($row['name'] ?? Craft::t('boomerang', 'Not given')),
            'isFault' => (bool)$row['isFault'],
            'returns' => (int)$row['returns'],
            'units' => (int)$row['units'],
            'value' => round((float)$row['value'], 2),
        ], $rows);
    }

    /**
     * Which products come back, and how often relative to how many were sold.
     *
     * The sold count is the honest denominator — a product returned 20 times out of 2,000 sold is
     * fine and a product returned 8 times out of 20 is a problem, and a report that only counts
     * returns says the opposite.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byProduct(?DateTime $from = null, ?DateTime $to = null, int $limit = 50): array
    {
        $rows = $this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->select([
                'purchasableId' => 'bri.purchasableId',
                'sku' => 'MAX([[bri.sku]])',
                'description' => 'MAX([[bri.description]])',
                'units' => 'SUM([[bri.qtyRequested]])',
                'returns' => 'COUNT(DISTINCT [[br.id]])',
                'value' => 'SUM([[bri.qtyRequested]] * ([[bri.unitPrice]] + [[bri.unitTax]]))',
            ])
            ->andWhere(['not', ['bri.purchasableId' => null]])
            ->groupBy(['bri.purchasableId'])
            ->orderBy(['units' => SORT_DESC])
            ->limit($limit)
            ->all();

        $sold = $this->soldQuantities(array_map(static fn(array $row) => (int)$row['purchasableId'], $rows), $from, $to);

        return array_map(function(array $row) use ($sold) {
            $id = (int)$row['purchasableId'];
            $units = (int)$row['units'];
            $soldQty = $sold[$id] ?? 0;

            return [
                'purchasableId' => $id,
                'sku' => (string)$row['sku'],
                'description' => (string)$row['description'],
                'units' => $units,
                'returns' => (int)$row['returns'],
                'value' => round((float)$row['value'], 2),
                'sold' => $soldQty,
                'rate' => $soldQty > 0 ? round($units / $soldQty * 100, 1) : null,
            ];
        }, $rows);
    }

    /**
     * Returns per day, for the chart.
     *
     * @return array<int, array{date: string, count: int, value: float}>
     */
    public function daily(?DateTime $from = null, ?DateTime $to = null): array
    {
        $expression = Craft::$app->getDb()->getIsPgsql()
            ? 'TO_CHAR([[br.requestedAt]], \'YYYY-MM-DD\')'
            : 'DATE_FORMAT([[br.requestedAt]], \'%Y-%m-%d\')';

        $rows = $this->baseQuery($from, $to)
            ->select([
                'day' => new \yii\db\Expression($expression),
                'count' => 'COUNT(*)',
                'value' => 'SUM([[br.resolutionAmount]])',
            ])
            ->groupBy([new \yii\db\Expression($expression)])
            ->orderBy(['day' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => [
            'date' => (string)$row['day'],
            'count' => (int)$row['count'],
            'value' => round((float)$row['value'], 2),
        ], $rows);
    }

    /**
     * How the store settles returns.
     *
     * @return array<string, array{count: int, value: float}>
     */
    public function byResolution(?DateTime $from = null, ?DateTime $to = null): array
    {
        $rows = $this->baseQuery($from, $to)
            ->select([
                'resolution' => 'br.resolution',
                'count' => 'COUNT(*)',
                'value' => 'SUM([[br.resolutionAmount]])',
            ])
            ->groupBy(['br.resolution'])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)($row['resolution'] ?? 'none')] = [
                'count' => (int)$row['count'],
                'value' => round((float)$row['value'], 2),
            ];
        }

        return $out;
    }

    /**
     * Store credit issued against store credit actually spent.
     *
     * The number that says whether the wallet is working. Credit issued and never redeemed is
     * revenue the store kept; credit redeemed is a customer who came back — which is the whole
     * argument for offering credit at all.
     *
     * @return array{issued: float, spent: float, expired: float, outstanding: float, redemptionRate: float|null}
     */
    public function credit(?DateTime $from = null, ?DateTime $to = null): array
    {
        $query = (new Query())
            ->from(['bce' => Table::CREDITENTRIES])
            ->select(['type' => 'bce.type', 'total' => 'SUM([[bce.amount]])'])
            ->groupBy(['bce.type']);

        if ($from !== null) {
            $query->andWhere(['>=', 'bce.dateCreated', Db::prepareDateForDb($from)]);
        }

        if ($to !== null) {
            $query->andWhere(['<=', 'bce.dateCreated', Db::prepareDateForDb($to)]);
        }

        $totals = [];

        foreach ($query->all() as $row) {
            $totals[(string)$row['type']] = round((float)$row['total'], 2);
        }

        $issued = $totals['issue'] ?? 0.0;
        $spent = abs($totals['spend'] ?? 0.0) - ($totals['refund'] ?? 0.0);
        $expired = abs($totals['expire'] ?? 0.0);

        $outstanding = (float)(new Query())
            ->from([Table::CREDITLOTS])
            ->where(['>', 'amountRemaining', 0])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', Db::prepareDateForDb(new DateTime())]])
            ->sum('[[amountRemaining]]');

        return [
            'issued' => $issued,
            'spent' => round(max(0.0, $spent), 2),
            'expired' => $expired,
            'outstanding' => round($outstanding, 2),
            'redemptionRate' => $issued > 0 ? round(max(0.0, $spent) / $issued * 100, 1) : null,
        ];
    }

    /**
     * How much of what came back went back on a shelf.
     *
     * @return array{received: int, restocked: int, rate: float|null, value: float}
     */
    public function restockRecovery(?DateTime $from = null, ?DateTime $to = null): array
    {
        $row = $this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->select([
                'received' => 'SUM([[bri.qtyReceived]])',
                'restocked' => 'SUM([[bri.qtyRestocked]])',
                'value' => 'SUM([[bri.qtyRestocked]] * [[bri.unitPrice]])',
            ])
            ->one();

        $received = (int)($row['received'] ?? 0);
        $restocked = (int)($row['restocked'] ?? 0);

        return [
            'received' => $received,
            'restocked' => $restocked,
            'rate' => $received > 0 ? round($restocked / $received * 100, 1) : null,
            'value' => round((float)($row['value'] ?? 0), 2),
        ];
    }

    /**
     * Everything on one screen, flattened for CSV.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(?DateTime $from = null, ?DateTime $to = null): array
    {
        return $this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->leftJoin(['bs' => Table::STATES], '[[bs.id]] = [[br.stateId]]')
            ->select([
                'reference' => 'br.reference',
                'orderReference' => 'br.orderReference',
                'email' => 'br.email',
                'state' => 'bs.name',
                'resolution' => 'br.resolution',
                'requestedAt' => 'br.requestedAt',
                'resolvedAt' => 'br.resolvedAt',
                'sku' => 'bri.sku',
                'description' => 'bri.description',
                'reason' => 'bri.reasonName',
                'condition' => 'bri.condition',
                'qtyRequested' => 'bri.qtyRequested',
                'qtyReceived' => 'bri.qtyReceived',
                'qtyRestocked' => 'bri.qtyRestocked',
                'unitPrice' => 'bri.unitPrice',
                'refunded' => 'br.refundedAmount',
                'credited' => 'br.creditedAmount',
                'fee' => 'br.feeAmount',
            ])
            ->orderBy(['br.requestedAt' => SORT_DESC, 'bri.id' => SORT_ASC])
            ->all();
    }

    // Internals
    // -------------------------------------------------------------------------

    private function baseQuery(?DateTime $from, ?DateTime $to): Query
    {
        $query = (new Query())
            ->from(['br' => Table::RETURNS])
            // Trashed elements are excluded here rather than by hydrating every RMA: a deleted
            // return is not data, and an analytics screen that counts the trash is worse than
            // useless because nobody can see what it counted.
            ->innerJoin(['e' => \craft\db\Table::ELEMENTS], '[[e.id]] = [[br.id]]')
            ->where(['e.dateDeleted' => null]);

        if ($from !== null) {
            $query->andWhere(['>=', 'br.requestedAt', Db::prepareDateForDb($from)]);
        }

        if ($to !== null) {
            $query->andWhere(['<=', 'br.requestedAt', Db::prepareDateForDb($to)]);
        }

        return $query;
    }

    /**
     * @param StateKind[] $kinds
     */
    private function countByKinds(?DateTime $from, ?DateTime $to, array $kinds): int
    {
        $ids = [];

        foreach (Plugin::getInstance()->states->getAllStates() as $state) {
            if (in_array($state->getKind(), $kinds, true)) {
                $ids[] = (int)$state->id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        return (int)$this->baseQuery($from, $to)->andWhere(['br.stateId' => $ids])->count();
    }

    /**
     * The share of returned units whose reason is the store's own fault.
     */
    private function faultRate(?DateTime $from, ?DateTime $to): ?float
    {
        $row = $this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->leftJoin(['brr' => Table::REASONS], '[[brr.id]] = [[bri.reasonId]]')
            ->select([
                'units' => 'SUM([[bri.qtyRequested]])',
                'fault' => 'SUM(CASE WHEN [[brr.isFault]] = ' . (Craft::$app->getDb()->getIsPgsql() ? 'TRUE' : '1') . ' THEN [[bri.qtyRequested]] ELSE 0 END)',
            ])
            ->one();

        $units = (int)($row['units'] ?? 0);

        return $units > 0 ? round((int)$row['fault'] / $units * 100, 1) : null;
    }

    /**
     * The median rather than the mean, because one return that sat over Christmas should not
     * define the number a team is judged on.
     */
    private function medianDaysToResolve(?DateTime $from, ?DateTime $to): ?float
    {
        $rows = $this->baseQuery($from, $to)
            ->select(['requestedAt' => 'br.requestedAt', 'resolvedAt' => 'br.resolvedAt'])
            ->andWhere(['not', ['br.resolvedAt' => null]])
            ->andWhere(['not', ['br.requestedAt' => null]])
            ->all();

        if ($rows === []) {
            return null;
        }

        $days = [];

        foreach ($rows as $row) {
            $requested = DateTimeHelper::toDateTime($row['requestedAt']);
            $resolved = DateTimeHelper::toDateTime($row['resolvedAt']);

            if ($requested === false || $resolved === false) {
                continue;
            }

            $days[] = max(0, ($resolved->getTimestamp() - $requested->getTimestamp()) / 86400);
        }

        if ($days === []) {
            return null;
        }

        sort($days);
        $middle = (int)floor(count($days) / 2);

        $median = count($days) % 2 === 0
            ? ($days[$middle - 1] + $days[$middle]) / 2
            : $days[$middle];

        return round($median, 1);
    }

    /**
     * Returned units as a share of units sold in the same period.
     *
     * @return array{returned: int, sold: int, rate: float|null}
     */
    private function returnRate(?DateTime $from, ?DateTime $to): array
    {
        $returned = (int)$this->baseQuery($from, $to)
            ->innerJoin(['bri' => Table::RETURNITEMS], '[[bri.returnId]] = [[br.id]]')
            ->sum('[[bri.qtyRequested]]');

        $soldQuery = (new Query())
            ->from(['li' => CommerceTable::LINEITEMS])
            ->innerJoin(['o' => CommerceTable::ORDERS], '[[o.id]] = [[li.orderId]]')
            ->where(['not', ['o.dateOrdered' => null]]);

        if ($from !== null) {
            $soldQuery->andWhere(['>=', 'o.dateOrdered', Db::prepareDateForDb($from)]);
        }

        if ($to !== null) {
            $soldQuery->andWhere(['<=', 'o.dateOrdered', Db::prepareDateForDb($to)]);
        }

        $sold = (int)$soldQuery->sum('[[li.qty]]');

        return [
            'returned' => $returned,
            'sold' => $sold,
            'rate' => $sold > 0 ? round($returned / $sold * 100, 2) : null,
        ];
    }

    /**
     * How many of each purchasable were sold in the period.
     *
     * @param int[] $purchasableIds
     * @return array<int, int>
     */
    private function soldQuantities(array $purchasableIds, ?DateTime $from, ?DateTime $to): array
    {
        if ($purchasableIds === []) {
            return [];
        }

        $query = (new Query())
            ->from(['li' => CommerceTable::LINEITEMS])
            ->innerJoin(['o' => CommerceTable::ORDERS], '[[o.id]] = [[li.orderId]]')
            ->select(['purchasableId' => 'li.purchasableId', 'qty' => 'SUM([[li.qty]])'])
            ->where(['li.purchasableId' => $purchasableIds])
            ->andWhere(['not', ['o.dateOrdered' => null]])
            ->groupBy(['li.purchasableId']);

        if ($from !== null) {
            $query->andWhere(['>=', 'o.dateOrdered', Db::prepareDateForDb($from)]);
        }

        if ($to !== null) {
            $query->andWhere(['<=', 'o.dateOrdered', Db::prepareDateForDb($to)]);
        }

        $sold = [];

        foreach ($query->all() as $row) {
            $sold[(int)$row['purchasableId']] = (int)$row['qty'];
        }

        return $sold;
    }
}
