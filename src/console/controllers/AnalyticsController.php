<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\Plugin;
use yii\console\ExitCode;

/**
 * The returns report, in a terminal.
 */
class AnalyticsController extends Controller
{
    /** How far back to look. */
    public int $days = 90;

    /** An explicit start date, overriding `--days`. */
    public ?string $from = null;

    public ?string $to = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'from', 'to']);
    }

    public function actionReport(): int
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("Return analytics need Boomerang Pro.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        [$from, $to] = $this->period();
        $analytics = Plugin::getInstance()->analytics;

        $summary = $analytics->summary($from, $to);

        $this->stdout(sprintf(
            "Returns %s → %s\n\n",
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ), Console::BOLD);

        $this->stdout(sprintf(
            "  Returns:            %d (%d open, %d resolved, %d rejected)\n"
            . "  Value:              %s  (refunded %s, credited %s, fees kept %s)\n"
            . "  Return rate:        %s of %d units sold\n"
            . "  Store's fault:      %s of returned units\n"
            . "  Median resolution:  %s days\n"
            . "  Restock recovery:   %s (%d of %d units back on a shelf)\n\n",
            $summary['total'],
            $summary['open'],
            $summary['resolved'],
            $summary['rejected'],
            number_format($summary['value'], 2),
            number_format($summary['refunded'], 2),
            number_format($summary['credited'], 2),
            number_format($summary['fees'], 2),
            $summary['returnRate']['rate'] !== null ? $summary['returnRate']['rate'] . '%' : '—',
            $summary['returnRate']['sold'],
            $summary['faultRate'] !== null ? $summary['faultRate'] . '%' : '—',
            $summary['medianDaysToResolve'] ?? '—',
            $summary['restockRecovery']['rate'] !== null ? $summary['restockRecovery']['rate'] . '%' : '—',
            $summary['restockRecovery']['restocked'],
            $summary['restockRecovery']['received'],
        ));

        $reasons = $analytics->byReason($from, $to);

        if ($reasons !== []) {
            $this->stdout("Why\n", Console::BOLD);
            $this->table(['Reason', 'Fault', 'Returns', 'Units', 'Value'], array_map(fn(array $row) => [
                $row['name'],
                $row['isFault'] ? 'yes' : '',
                (string)$row['returns'],
                (string)$row['units'],
                number_format($row['value'], 2),
            ], $reasons));
            $this->stdout("\n");
        }

        $products = $analytics->byProduct($from, $to, 15);

        if ($products !== []) {
            $this->stdout("What\n", Console::BOLD);
            $this->table(['SKU', 'Item', 'Units back', 'Sold', 'Rate'], array_map(fn(array $row) => [
                $row['sku'],
                mb_strimwidth($row['description'], 0, 40, '…'),
                (string)$row['units'],
                (string)$row['sold'],
                $row['rate'] !== null ? $row['rate'] . '%' : '—',
            ], $products));
            $this->stdout("\n");
        }

        $credit = $analytics->credit($from, $to);

        $this->stdout("Store credit\n", Console::BOLD);
        $this->stdout(sprintf(
            "  Issued %s, spent %s, expired %s, outstanding %s%s\n",
            number_format($credit['issued'], 2),
            number_format($credit['spent'], 2),
            number_format($credit['expired'], 2),
            number_format($credit['outstanding'], 2),
            $credit['redemptionRate'] !== null ? sprintf(' — %s%% redeemed', $credit['redemptionRate']) : '',
        ));

        return ExitCode::OK;
    }

    /**
     * @return array{0: DateTime, 1: DateTime}
     */
    private function period(): array
    {
        $to = $this->to !== null ? (DateTimeHelper::toDateTime($this->to) ?: new DateTime()) : new DateTime();

        $from = $this->from !== null
            ? (DateTimeHelper::toDateTime($this->from) ?: (clone $to)->sub(new DateInterval('P' . $this->days . 'D')))
            : (clone $to)->sub(new DateInterval('P' . max(1, $this->days) . 'D'));

        return [$from, $to];
    }
}
