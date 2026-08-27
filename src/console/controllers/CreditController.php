<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\CreditEntryType;
use justinholtweb\boomerang\Plugin;
use yii\console\ExitCode;

/**
 * Store credit housekeeping.
 *
 * `boomerang/credit/expire` belongs in cron. Expiry is enforced in the balance query as well, so
 * an unrun cron cannot let expired credit be spent — but until this runs, the ledger does not say
 * where the money went, and a customer asking will get an answer nobody can substantiate.
 */
class CreditController extends Controller
{
    /** Do not write anything. */
    public bool $dryRun = false;

    /** Days ahead, for `warn`. */
    public ?int $days = null;

    /** Hours an uncaptured hold may sit before `release-holds` gives it back. */
    public int $hours = 72;

    public function options($actionID): array
    {
        return match ($actionID) {
            'expire' => array_merge(parent::options($actionID), ['dryRun']),
            'warn' => array_merge(parent::options($actionID), ['days', 'dryRun']),
            'release-holds' => array_merge(parent::options($actionID), ['hours', 'dryRun']),
            default => parent::options($actionID),
        };
    }

    /**
     * Write off every lot that has lapsed.
     */
    public function actionExpire(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $rows = (new Query())
                ->from([Table::CREDITLOTS])
                ->where(['>', 'amountRemaining', 0])
                ->andWhere(['not', ['expiresAt' => null]])
                ->andWhere(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime())])
                ->all();

            $this->stdout(sprintf("%d lots would expire, worth %s.\n", count($rows), number_format(
                array_sum(array_map(static fn(array $row) => (float)$row['amountRemaining'], $rows)),
                2,
            )));

            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->wallet->expire();

        $this->stdout(sprintf("Expired %d lots, worth %s.\n", $result['lots'], number_format($result['amount'], 2)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Email customers whose credit is about to lapse.
     */
    public function actionWarn(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $days = $this->days ?? $plugin->getSettings()->creditExpiryWarningDays;

        if ($days < 1) {
            $this->stdout("Expiry warnings are switched off.\n");

            return ExitCode::OK;
        }

        $cutoff = (new DateTime())->add(new DateInterval('P' . $days . 'D'));

        $rows = (new Query())
            ->from([Table::CREDITLOTS])
            ->select([
                'customerId',
                'amount' => 'SUM([[amountRemaining]])',
                'expiresAt' => 'MIN([[expiresAt]])',
            ])
            ->where(['>', 'amountRemaining', 0])
            ->andWhere(['not', ['expiresAt' => null]])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->andWhere(['<=', 'expiresAt', Db::prepareDateForDb($cutoff)])
            ->groupBy(['customerId'])
            ->all();

        $sent = 0;

        foreach ($rows as $row) {
            $expiresAt = \craft\helpers\DateTimeHelper::toDateTime($row['expiresAt']);

            if ($expiresAt === false) {
                continue;
            }

            if ($this->dryRun) {
                $this->stdout(sprintf(
                    "  would warn customer %d about %s expiring %s\n",
                    $row['customerId'],
                    number_format((float)$row['amount'], 2),
                    $expiresAt->format('Y-m-d'),
                ));

                continue;
            }

            if ($plugin->notifications->creditExpiring((int)$row['customerId'], round((float)$row['amount'], 2), $expiresAt)) {
                $sent++;
            }
        }

        $this->stdout($this->dryRun
            ? sprintf("%d customers would be warned.\n", count($rows))
            : sprintf("Warned %d customers.\n", $sent), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Give back authorizations nobody ever captured.
     *
     * Commerce has no concept of a gateway voiding an authorization, so a merchant who authorizes
     * and never captures leaves the customer's credit locked up with nothing to unlock it. This is
     * that lock's key, and it belongs in cron next to `expire`.
     */
    public function actionReleaseHolds(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $cutoff = (new DateTime())->sub(new DateInterval('PT' . max(1, $this->hours) . 'H'));

        $hashes = (new Query())
            ->from([Table::CREDITENTRIES])
            ->select(['transactionHash'])
            ->where(['type' => CreditEntryType::Hold->value])
            ->andWhere(['not', ['transactionHash' => null]])
            ->andWhere(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->distinct()
            ->column();

        $released = 0.0;
        $count = 0;

        foreach ($hashes as $hash) {
            if ($this->dryRun) {
                $this->stdout("  would release hold $hash\n");
                $count++;

                continue;
            }

            $amount = Plugin::getInstance()->wallet->releaseHolds((string)$hash);

            if ($amount > 0) {
                $released += $amount;
                $count++;
            }
        }

        $this->stdout(sprintf(
            "%s %d holds%s.\n",
            $this->dryRun ? 'Would release' : 'Released',
            $count,
            $this->dryRun ? '' : sprintf(', worth %s', number_format($released, 2)),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * One customer's balance and ledger.
     */
    public function actionBalance(string $email): int
    {
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        if ($user === null) {
            $this->stderr("No user with that email.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();

        $this->stdout(sprintf("%s: %s\n\n", $user->email, number_format($plugin->wallet->getBalance((int)$user->id), 2)), Console::BOLD);

        $rows = [];

        foreach ($plugin->wallet->getEntries((int)$user->id, limit: 50) as $entry) {
            $rows[] = [
                $entry->dateCreated?->format('Y-m-d H:i') ?? '',
                $entry->getType()->label(),
                number_format($entry->amount, 2),
                (string)$entry->lotId,
                $entry->note ?? '',
            ];
        }

        if ($rows !== []) {
            $this->table(['When', 'What', 'Amount', 'Lot', 'Note'], $rows);
        }

        return ExitCode::OK;
    }

    private function requirePro(): bool
    {
        if (Plugin::getInstance()->isPro()) {
            return true;
        }

        $this->stderr("Store credit needs Boomerang Pro.\n", Console::FG_RED);

        return false;
    }
}
