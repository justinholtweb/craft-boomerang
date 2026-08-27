<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * What the returns are telling you.
 */
class AnalyticsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_ANALYTICS);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('boomerang', 'Return analytics need Boomerang Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        [$from, $to, $range] = $this->period();

        return $this->renderTemplate('boomerang/analytics/_index', [
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'summary' => $plugin->analytics->summary($from, $to),
            'byReason' => $plugin->analytics->byReason($from, $to),
            'byProduct' => $plugin->analytics->byProduct($from, $to, 25),
            'byResolution' => $plugin->analytics->byResolution($from, $to),
            'daily' => $plugin->analytics->daily($from, $to),
            'credit' => $plugin->analytics->credit($from, $to),
        ]);
    }

    /**
     * Everything on screen, as CSV.
     */
    public function actionExport(): Response
    {
        [$from, $to] = $this->period();

        $rows = Plugin::getInstance()->analytics->exportRows($from, $to);

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'RMA', 'Order', 'Email', 'State', 'Resolution', 'Requested', 'Resolved', 'SKU',
            'Description', 'Reason', 'Condition', 'Qty requested', 'Qty received', 'Qty restocked',
            'Unit price', 'Refunded', 'Credited', 'Fee',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($csv, 'boomerang-returns.csv', ['mimeType' => 'text/csv']);
    }

    /**
     * @return array{0: DateTime, 1: DateTime, 2: string}
     */
    private function period(): array
    {
        $range = (string)$this->request->getParam('range', 'last90');
        $to = new DateTime();

        $from = match ($range) {
            'last7' => (clone $to)->sub(new DateInterval('P7D')),
            'last30' => (clone $to)->sub(new DateInterval('P30D')),
            'last365' => (clone $to)->sub(new DateInterval('P365D')),
            'custom' => DateTimeHelper::toDateTime($this->request->getParam('from')) ?: (clone $to)->sub(new DateInterval('P90D')),
            default => (clone $to)->sub(new DateInterval('P90D')),
        };

        if ($range === 'custom') {
            $to = DateTimeHelper::toDateTime($this->request->getParam('to')) ?: $to;
        }

        return [$from, $to, $range];
    }
}
