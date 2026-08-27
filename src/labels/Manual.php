<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\labels;

use Craft;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\models\Label;
use RuntimeException;

/**
 * A label the merchant already has.
 *
 * Bought at the post office counter, printed from the carrier's own portal, or included in the
 * box. The merchant types the tracking number and uploads the PDF; Boomerang stores it, shows it
 * on the portal and tracks it like any other. This is the provider most stores will actually use,
 * and it makes no outbound request at all.
 */
class Manual implements LabelProviderInterface
{
    public static function handle(): string
    {
        return 'manual';
    }

    public static function displayName(): string
    {
        return Craft::t('boomerang', 'Manual');
    }

    public function isConfigured(array &$errors = []): bool
    {
        return true;
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function createLabel(ReturnRequest $return, array $params = []): Label
    {
        $tracking = trim((string)($params['trackingNumber'] ?? ''));
        $assetId = isset($params['assetId']) ? (int)$params['assetId'] : null;
        $url = trim((string)($params['labelUrl'] ?? ''));

        if ($tracking === '' && $assetId === null && $url === '') {
            throw new RuntimeException(Craft::t('boomerang', 'Enter a tracking number or attach a label.'));
        }

        $label = new Label();
        $label->returnId = $return->id;
        $label->provider = self::handle();
        $label->carrier = trim((string)($params['carrier'] ?? '')) ?: null;
        $label->service = trim((string)($params['service'] ?? '')) ?: null;
        $label->trackingNumber = $tracking ?: null;
        $label->trackingUrl = trim((string)($params['trackingUrl'] ?? '')) ?: null;
        $label->cost = isset($params['cost']) && $params['cost'] !== '' ? (float)$params['cost'] : null;
        $label->currency = $return->currency;
        $label->labelUrl = $url ?: null;
        $label->assetId = $assetId;
        $label->paidByStore = (bool)($params['paidByStore'] ?? $return->getHasFreeReturnShipping());

        return $label;
    }

    public function voidLabel(Label $label): bool
    {
        // Nothing to tell anybody. The row is marked voided by the service either way.
        return false;
    }
}
