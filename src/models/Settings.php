<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\Plugin;

/**
 * Boomerang's settings.
 *
 * ## Pro settings are ignored on Lite, not obeyed
 *
 * A site that downgrades keeps its configuration — throwing it away would punish somebody for a
 * billing lapse — but every Pro switch is read through a `getEffective*()` gate that returns the
 * Lite answer regardless of what is stored. {@see getSuppressedProSettings()} names what is being
 * ignored so the CP can say so out loud, rather than a merchant discovering it from a customer.
 *
 * Nothing here is marked `required`: a `required` rule on one credential blocks saving *every*
 * other setting on a fresh install.
 */
class Settings extends Model
{
    public const WINDOW_FROM_ORDERED = 'ordered';
    public const WINDOW_FROM_PAID = 'paid';
    public const WINDOW_FROM_CUSTOM = 'custom';

    // Policy
    // -------------------------------------------------------------------------

    /** How many days a customer has to start a return. 0 means no window at all. */
    public int $returnWindowDays = 30;

    /** Which date the window counts from. */
    public string $windowStartsFrom = self::WINDOW_FROM_ORDERED;

    /**
     * An order attribute or custom field handle holding the delivery date, for stores that track
     * one. Only consulted when {@see $windowStartsFrom} is `custom`; falls back to the order date
     * when the field is empty, because a window that silently never opens is worse than a
     * generous one.
     */
    public string $windowStartsFromField = '';

    /** Only completed orders can be returned. Off is for stores completing orders late. */
    public bool $requireCompletedOrder = true;

    /** Only paid orders can be returned. */
    public bool $requirePaidOrder = true;

    /** Order status UIDs a return may be started from. Empty means any. */
    public array $eligibleOrderStatusUids = [];

    /** Product type UIDs that can never be returned. */
    public array $excludedProductTypeUids = [];

    /** SKUs that can never be returned. `*` wildcards allowed. */
    public array $excludedSkus = [];

    /** Whether a customer may send back some of a line rather than all of it. */
    public bool $allowPartialQty = true;

    /** Whether an order may have more than one RMA against it over time. */
    public bool $allowMultipleReturns = true;

    // Approval
    // -------------------------------------------------------------------------

    /** Skip the approval step entirely: every request lands approved. */
    public bool $autoApprove = false;

    /** Auto-approve requests worth less than this. 0 is off. */
    public float $autoApproveUnder = 0.0;

    // Money
    // -------------------------------------------------------------------------

    public bool $refundIncludesTax = true;

    /** Refund the shipping attributable to the returned items. Usually not. */
    public bool $refundIncludesShipping = false;

    /** …unless the whole order came back, in which case shipping usually goes too. */
    public bool $refundShippingOnFullReturn = true;

    /** A percentage held back on every refund. */
    public float $restockingFeePercent = 0.0;

    /** A flat amount held back on every refund. */
    public float $restockingFeeFlat = 0.0;

    /** No fee when the return is the store's fault. On by default, because charging for your own
     *  mistake is how a return becomes a chargeback. */
    public bool $waiveFeeOnFault = true;

    /** What a new RMA proposes when nobody has chosen. */
    public string $defaultResolution = Resolution::Refund->value;

    // Store credit (Pro)
    // -------------------------------------------------------------------------

    public bool $creditEnabled = true;

    /** Uplift on credit relative to a cash refund — the "110% back as credit" lever. */
    public float $creditBonusPercent = 0.0;

    /** Days until issued credit expires. 0 never expires. */
    public int $creditExpiryDays = 0;

    /** Warn the customer this many days before their credit lapses. 0 is off. */
    public int $creditExpiryWarningDays = 14;

    /** Minimum balance before the wallet offers itself at checkout. */
    public float $creditMinimumSpend = 0.0;

    // Restock (Pro)
    // -------------------------------------------------------------------------

    public bool $restockEnabled = true;

    /** Where returned goods land. Null uses the store's first inventory location. */
    public ?int $defaultInventoryLocationId = null;

    // Labels (Pro)
    // -------------------------------------------------------------------------

    /** The provider handle used to obtain return labels. */
    public string $labelProvider = 'manual';

    /** ShipStation v2 API key. Env-able. */
    public string $shipStationApiKey = '';

    /** The ShipStation carrier and service the return label is bought against. */
    public string $shipStationCarrierId = '';
    public string $shipStationServiceCode = '';

    /**
     * Take the return address from the inventory location goods are restocked into.
     *
     * That is the right default: a return label sends the parcel to the warehouse that is going to
     * put the stock back, and Commerce already knows that address. Commerce 5 has no store-level
     * location address to borrow instead.
     */
    public bool $useLocationAddressForReturns = true;

    /**
     * The address goods come back to, when it is not an inventory location's.
     *
     * Keys: `name`, `company`, `addressLine1`, `addressLine2`, `locality`, `administrativeArea`,
     * `postalCode`, `countryCode`, `phone`.
     */
    public array $returnAddress = [];

    /** Volume UID that label PDFs are copied into, so they outlive the provider's link. */
    public string $labelVolumeUid = '';

    // Portal
    // -------------------------------------------------------------------------

    public bool $portalEnabled = true;

    /** URI the portal is mounted at. Empty leaves the merchant to route it themselves. */
    public string $portalUriPrefix = 'returns';

    /** Guests must sign in first. Off lets them look an order up by number and email. */
    public bool $portalRequiresLogin = false;

    /** Portal submissions allowed per IP per hour. 0 is off. */
    public int $portalRateLimit = 20;

    public bool $portalAllowsCancel = true;

    public bool $portalAllowsPhotos = true;
    public string $photoVolumeUid = '';
    public int $maxPhotosPerItem = 3;

    /** Kilobytes. */
    public int $maxPhotoSize = 5120;

    /** Shown above the request form. Markdown-free plain text or HTML the merchant wrote. */
    public string $policyText = '';

    /** A page on the site setting out the returns policy in full. */
    public string $policyUrl = '';

    // Notifications
    // -------------------------------------------------------------------------

    /** Master switch. Per-state `notifyCustomer` decides which transitions actually send. */
    public bool $notifyCustomer = true;

    public bool $notifyStaff = true;

    /** Who hears about a new return. Empty falls back to the system email address. */
    public array $staffRecipients = [];

    public string $fromName = '';
    public string $fromEmail = '';

    /** Send through the queue. Off sends in-request, which is what a test suite wants. */
    public bool $queueNotifications = true;

    // Numbering
    // -------------------------------------------------------------------------

    public string $referencePrefix = 'RMA-';
    public int $referencePadding = 5;
    public int $referenceStartsAt = 1;

    // Retention
    // -------------------------------------------------------------------------

    /** Delete closed RMAs after this many days. 0 keeps them forever, which is the default:
     *  a returns history is a compliance record, and nobody should lose one by accident. */
    public int $pruneClosedAfterDays = 0;

    // Effective values
    // -------------------------------------------------------------------------

    public function getEffectiveCreditEnabled(): bool
    {
        return $this->creditEnabled && Plugin::getInstance()->isPro();
    }

    public function getEffectiveRestockEnabled(): bool
    {
        return $this->restockEnabled && Plugin::getInstance()->isPro();
    }

    public function getEffectiveLabelProvider(): ?string
    {
        return Plugin::getInstance()->isPro() ? $this->labelProvider : null;
    }

    public function getEffectiveCreditBonusPercent(): float
    {
        return $this->getEffectiveCreditEnabled() ? $this->creditBonusPercent : 0.0;
    }

    /**
     * The resolutions this install may actually carry out.
     *
     * @return array<string, string>
     */
    public function getAvailableResolutions(): array
    {
        $pro = Plugin::getInstance()->isPro();
        $options = [];

        foreach (Resolution::cases() as $case) {
            if ($case->isPro() && !$pro) {
                continue;
            }

            if ($case === Resolution::Credit && !$this->getEffectiveCreditEnabled()) {
                continue;
            }

            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function getDefaultResolution(): Resolution
    {
        $resolution = Resolution::tryFrom($this->defaultResolution) ?? Resolution::Refund;

        // A stored default of "store credit" on a downgraded install would otherwise propose a
        // resolution the plugin cannot carry out.
        if (!isset($this->getAvailableResolutions()[$resolution->value])) {
            return Resolution::Refund;
        }

        return $resolution;
    }

    /**
     * Pro settings that are switched on and being ignored.
     *
     * @return string[]
     */
    public function getSuppressedProSettings(): array
    {
        if (Plugin::getInstance()->isPro()) {
            return [];
        }

        $suppressed = [];

        if ($this->creditEnabled) {
            $suppressed[] = Craft::t('boomerang', 'Store credit');
        }

        if ($this->restockEnabled) {
            $suppressed[] = Craft::t('boomerang', 'Restocking into inventory');
        }

        if ($this->labelProvider !== '' && $this->labelProvider !== 'none') {
            $suppressed[] = Craft::t('boomerang', 'Return labels');
        }

        return $suppressed;
    }

    public function getHasSuppressedProSettings(): bool
    {
        return $this->getSuppressedProSettings() !== [];
    }

    public function getShipStationApiKey(): string
    {
        return trim((string)App::parseEnv($this->shipStationApiKey));
    }

    public function getFromEmail(): ?string
    {
        $email = trim((string)App::parseEnv($this->fromEmail));

        return $email !== '' ? $email : null;
    }

    public function getFromName(): ?string
    {
        $name = trim((string)App::parseEnv($this->fromName));

        return $name !== '' ? $name : null;
    }

    /**
     * Who to tell about a new return, falling back to the system email address so that "notify
     * staff" with nothing typed in still notifies somebody.
     *
     * @return string[]
     */
    public function getStaffRecipients(): array
    {
        $recipients = array_values(array_filter(array_map(
            fn($email) => trim((string)App::parseEnv(is_array($email) ? ($email['value'] ?? '') : $email)),
            $this->staffRecipients,
        )));

        if ($recipients !== []) {
            return $recipients;
        }

        $system = Craft::$app->getProjectConfig()->get('email.fromEmail');

        return $system ? [(string)App::parseEnv($system)] : [];
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['returnWindowDays', 'referencePadding', 'referenceStartsAt', 'maxPhotosPerItem', 'maxPhotoSize', 'portalRateLimit', 'creditExpiryDays', 'creditExpiryWarningDays', 'pruneClosedAfterDays'], 'integer', 'min' => 0];
        $rules[] = [['restockingFeePercent', 'creditBonusPercent'], 'number', 'min' => 0, 'max' => 100];
        $rules[] = [['restockingFeeFlat', 'autoApproveUnder', 'creditMinimumSpend'], 'number', 'min' => 0];
        $rules[] = [['windowStartsFrom'], 'in', 'range' => [self::WINDOW_FROM_ORDERED, self::WINDOW_FROM_PAID, self::WINDOW_FROM_CUSTOM]];
        $rules[] = [['defaultResolution'], 'in', 'range' => array_column(Resolution::cases(), 'value')];
        $rules[] = [['fromEmail'], 'email', 'skipOnEmpty' => true, 'when' => fn(self $model) => !str_contains($model->fromEmail, '$')];
        $rules[] = [['referencePrefix'], 'string', 'max' => 32];
        $rules[] = [
            ['eligibleOrderStatusUids', 'excludedProductTypeUids', 'excludedSkus', 'staffRecipients', 'returnAddress'],
            'safe',
        ];

        return $rules;
    }

    /**
     * Editable tables and multiselects post shapes a flat cast cannot take.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (['excludedSkus', 'staffRecipients'] as $attribute) {
            if (isset($values[$attribute]) && is_array($values[$attribute])) {
                $values[$attribute] = array_values(array_filter(array_map(
                    static fn($row) => is_array($row) ? trim((string)reset($row)) : trim((string)$row),
                    $values[$attribute],
                ), static fn(string $value) => $value !== ''));
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
