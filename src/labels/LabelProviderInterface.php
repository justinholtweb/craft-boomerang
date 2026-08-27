<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\labels;

use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\models\Label;

/**
 * Something that can produce a return label.
 *
 * The interface is the product here, not any one carrier. A returns plugin whose labels come from
 * exactly one vendor is a plugin held hostage to that vendor's roadmap, and every store that
 * already buys postage somewhere else has to fork it. Two implementations ship — {@see Manual} and
 * {@see ShipStation} — and a merchant's own is registered on
 * `Plugin::EVENT_REGISTER_LABEL_PROVIDERS`.
 */
interface LabelProviderInterface
{
    /**
     * The handle stored on the label row and in settings. Stable forever.
     */
    public static function handle(): string;

    public static function displayName(): string;

    /**
     * Whether this provider is configured well enough to be asked for a label.
     *
     * @param string[] $errors Filled with what is missing, for the settings screen.
     */
    public function isConfigured(array &$errors = []): bool;

    /**
     * Whether this provider reaches out over the network.
     *
     * The portal uses it to decide whether asking for a label is instant or worth a queue job.
     */
    public function isRemote(): bool;

    /**
     * Produce a label for a return.
     *
     * `$params` carries anything the caller collected — a manual tracking number, a chosen
     * service, an asset ID — and providers ignore what they do not understand.
     *
     * @throws \RuntimeException when a label cannot be produced. The message reaches the merchant.
     */
    public function createLabel(ReturnRequest $return, array $params = []): Label;

    /**
     * Cancel a label with the carrier, if it can be.
     *
     * @return bool False when the provider cannot void, which is not an error — the label row is
     *              still marked voided locally so nobody sends the customer a dead label.
     */
    public function voidLabel(Label $label): bool;
}
