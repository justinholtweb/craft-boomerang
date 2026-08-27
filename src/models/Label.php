<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * A return label obtained for an RMA.
 *
 * Kept as its own row rather than as columns on the return, because a return can need a second
 * label — the first went to the wrong address, the customer lost it, the parcel was split — and a
 * merchant chasing a package needs to see both, with the voided one still readable.
 */
class Label extends Model
{
    public ?int $id = null;
    public ?int $returnId = null;

    /** The provider handle that produced it: `manual`, `shipstation`, or a merchant's own. */
    public string $provider = 'manual';

    /** The provider's own identifier, for voiding and tracking. */
    public ?string $providerLabelId = null;

    public ?string $carrier = null;
    public ?string $service = null;
    public ?string $trackingNumber = null;
    public ?string $trackingUrl = null;
    public ?float $cost = null;
    public ?string $currency = null;

    /** A URL at the provider. Providers expire these, which is why {@see $assetId} exists. */
    public ?string $labelUrl = null;

    /** A copy of the label stored in a Craft volume, so it outlives the provider's link. */
    public ?int $assetId = null;

    /** Whether the store paid for it, as opposed to the customer. */
    public bool $paidByStore = true;

    public ?string $rawResponse = null;
    public ?DateTime $voidedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['voidedAt']);
    }

    public function getIsVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    public function getAsset(): ?Asset
    {
        return $this->assetId !== null
            ? Craft::$app->getElements()->getElementById($this->assetId, Asset::class)
            : null;
    }

    /**
     * Where the label can actually be fetched from now.
     *
     * The stored asset first: a provider's URL is a temporary link to somebody else's server, and
     * the day a customer clicks it is not necessarily the day it was made.
     */
    public function getDownloadUrl(): ?string
    {
        $asset = $this->getAsset();

        if ($asset !== null) {
            return $asset->getUrl();
        }

        return $this->labelUrl;
    }

    public function getCpDownloadUrl(): string
    {
        return UrlHelper::actionUrl('boomerang/labels/download', ['labelId' => $this->id]);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['provider'], 'required'];
        $rules[] = [['provider', 'carrier', 'service', 'trackingNumber', 'providerLabelId'], 'string', 'max' => 255];
        $rules[] = [['cost'], 'number'];
        $rules[] = [['paidByStore'], 'boolean'];
        $rules[] = [['labelUrl', 'trackingUrl', 'rawResponse'], 'string'];

        return $rules;
    }
}
