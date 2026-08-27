<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;
use yii\console\ExitCode;

/**
 * Returns, from the command line.
 *
 * Reachable as `craft boomerang/returns/<action>` — plugin console commands are listed under
 * `craft help`, not under `craft help boomerang`.
 */
class ReturnsController extends Controller
{
    /** Limit the listing. */
    public int $limit = 50;

    /** A state handle to filter by, or to move returns to. */
    public ?string $state = null;

    /** Show what would happen without doing it. */
    public bool $dryRun = false;

    /** Days, for `prune`. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'list' => array_merge(parent::options($actionID), ['limit', 'state']),
            'prune' => array_merge(parent::options($actionID), ['days', 'dryRun']),
            'stalled' => array_merge(parent::options($actionID), ['days', 'limit']),
            default => parent::options($actionID),
        };
    }

    /**
     * List returns.
     */
    public function actionList(): int
    {
        $query = ReturnRequest::find()->limit($this->limit);

        if ($this->state !== null) {
            $query->state($this->state);
        }

        $rows = [];

        foreach ($query->all() as $return) {
            $rows[] = [
                $return->reference,
                $return->getState()?->name ?? '—',
                $return->orderReference ?: '—',
                $return->email,
                (string)$return->getTotalQtyRequested(),
                number_format($return->resolutionAmount, 2),
                $return->requestedAt?->format('Y-m-d') ?? '',
            ];
        }

        if ($rows === []) {
            $this->stdout("No returns.\n");

            return ExitCode::OK;
        }

        $this->table(['RMA', 'State', 'Order', 'Email', 'Qty', 'Value', 'Requested'], $rows);

        return ExitCode::OK;
    }

    /**
     * Show one return in full — items, history, money.
     */
    public function actionShow(string $reference): int
    {
        $return = Plugin::getInstance()->returns->getReturnByReference($reference);

        if ($return === null) {
            $this->stderr("No return with reference $reference\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("$return->reference\n", Console::BOLD);
        $this->stdout(sprintf(
            "  State:      %s\n  Order:      %s\n  Customer:   %s\n  Resolution: %s\n  Value:      %s (fee %s, refunded %s, credited %s)\n\n",
            $return->getState()?->name ?? '—',
            $return->orderReference ?: '—',
            $return->email,
            $return->getResolution()->label(),
            number_format($return->resolutionAmount, 2),
            number_format($return->feeAmount, 2),
            number_format($return->refundedAmount, 2),
            number_format($return->creditedAmount, 2),
        ));

        $items = [];

        foreach ($return->getItems() as $item) {
            $items[] = [
                $item->sku,
                $item->description,
                $item->getReasonLabel(),
                sprintf('%d / %d / %d', $item->qtyRequested, $item->qtyReceived, $item->qtyRestocked),
                $item->getCondition()->label(),
            ];
        }

        if ($items !== []) {
            $this->table(['SKU', 'Item', 'Reason', 'Req/Rec/Restock', 'Condition'], $items);
        }

        $this->stdout("\nHistory\n", Console::BOLD);

        foreach (array_reverse($return->getEvents()) as $event) {
            $this->stdout(sprintf(
                "  %s  %-18s %s %s\n",
                $event->dateCreated?->format('Y-m-d H:i') ?? '',
                $event->getType()->value,
                $event->getActorLabel(),
                $event->message ?? '',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Move a return to a state, with everything that entails.
     */
    public function actionTransition(string $reference, string $stateHandle): int
    {
        $plugin = Plugin::getInstance();
        $return = $plugin->returns->getReturnByReference($reference);

        if ($return === null) {
            $this->stderr("No return with reference $reference\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $state = $plugin->states->getStateByHandle($stateHandle);

        if ($state === null) {
            $this->stderr("No state with handle $stateHandle\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!$plugin->returns->transition($return, $state)) {
            $this->stderr(implode("\n", $return->getErrorSummary(true)) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("$return->reference is now $state->name\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Returns nobody has touched in a while.
     *
     * The report a returns desk should be reading on a Monday: an RMA that has sat in "Approved"
     * for three weeks is a customer who is about to raise a chargeback.
     */
    public function actionStalled(): int
    {
        $days = $this->days ?? 14;
        $cutoff = (new DateTime())->sub(new DateInterval('P' . $days . 'D'));

        $returns = ReturnRequest::find()
            ->isOpen(true)
            ->limit($this->limit)
            ->all();

        $rows = [];

        foreach ($returns as $return) {
            $changed = $return->stateChangedAt ?? $return->requestedAt;

            if ($changed === null || $changed > $cutoff) {
                continue;
            }

            $rows[] = [
                $return->reference,
                $return->getState()?->name ?? '—',
                $return->email,
                (string)(int)$changed->diff(new DateTime())->days,
                number_format($return->resolutionAmount, 2),
            ];
        }

        if ($rows === []) {
            $this->stdout("Nothing has been sitting for more than $days days.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%d returns untouched for more than %d days\n\n", count($rows), $days), Console::FG_YELLOW);
        $this->table(['RMA', 'State', 'Email', 'Days', 'Value'], $rows);

        return ExitCode::OK;
    }

    /**
     * Delete closed returns older than the retention period.
     *
     * Off by default and opt-in per run, because a returns history is a compliance record and
     * nobody should lose one to a cron job they forgot they set up.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? Plugin::getInstance()->getSettings()->pruneClosedAfterDays;

        if ($days < 1) {
            $this->stdout("No retention period is set — pass --days to prune anyway.\n");

            return ExitCode::OK;
        }

        $cutoff = (new DateTime())->sub(new DateInterval('P' . $days . 'D'));

        $returns = ReturnRequest::find()
            ->isArchived(null)
            ->isOpen(false)
            ->andWhere(['<', 'boomerang_returns.stateChangedAt', Db::prepareDateForDb($cutoff)])
            ->all();

        if ($returns === []) {
            $this->stdout("Nothing to prune.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%d closed returns older than %d days\n", count($returns), $days));

        if ($this->dryRun) {
            foreach ($returns as $return) {
                $this->stdout("  would delete $return->reference\n");
            }

            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm('Delete them?')) {
            return ExitCode::OK;
        }

        foreach ($returns as $return) {
            Craft::$app->getElements()->deleteElement($return, true);
        }

        $this->stdout(sprintf("Deleted %d.\n", count($returns)), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
