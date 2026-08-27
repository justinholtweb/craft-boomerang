<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\twig;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\models\Eligibility;
use justinholtweb\boomerang\models\Reason;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;

/**
 * `craft.boomerang` — everything a front-end template needs.
 *
 * Every method here is a read: nothing in Twig can move a return, spend credit or issue a refund.
 * A template that could would be a template that does, the first time somebody caches a page.
 */
class BoomerangVariable
{
    /**
     * A returns query. `craft.boomerang.returns.customerId(currentUser.id).all()`
     */
    public function returns(array $criteria = []): ElementQueryInterface
    {
        $query = ReturnRequest::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * The signed-in customer's returns.
     *
     * @return ReturnRequest[]
     */
    public function myReturns(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null
            ? ReturnRequest::find()->customerId($user->id)->isArchived(null)->all()
            : [];
    }

    /**
     * Whether an order can be returned, with the full trace.
     */
    public function eligibility(Order $order): Eligibility
    {
        return Plugin::getInstance()->eligibility->evaluate($order);
    }

    public function canReturn(Order $order): bool
    {
        return Plugin::getInstance()->eligibility->isEligible($order);
    }

    /**
     * @return Reason[]
     */
    public function reasons(bool $customerOnly = true): array
    {
        return $customerOnly
            ? Plugin::getInstance()->reasons->getCustomerReasons()
            : Plugin::getInstance()->reasons->getEnabledReasons();
    }

    /**
     * @return State[]
     */
    public function states(): array
    {
        return Plugin::getInstance()->states->getAllStates();
    }

    /**
     * The signed-in customer's spendable store credit.
     */
    public function balance(?int $customerId = null, ?int $storeId = null, ?string $currency = null): float
    {
        $customerId ??= Craft::$app->getUser()->getId();

        if ($customerId === null || !Plugin::getInstance()->getSettings()->getEffectiveCreditEnabled()) {
            return 0.0;
        }

        return Plugin::getInstance()->wallet->getBalance((int)$customerId, $storeId, $currency);
    }

    /**
     * How much of that balance lapses soon, for a "use it before {date}" nudge.
     */
    public function expiringBalance(?int $days = null, ?int $customerId = null): float
    {
        $customerId ??= Craft::$app->getUser()->getId();
        $settings = Plugin::getInstance()->getSettings();

        if ($customerId === null || !$settings->getEffectiveCreditEnabled()) {
            return 0.0;
        }

        return Plugin::getInstance()->wallet->getExpiringBalance(
            (int)$customerId,
            $days ?? $settings->creditExpiryWarningDays,
        );
    }

    /**
     * The customer's credit lots, so a template can list what expires when.
     *
     * @return \justinholtweb\boomerang\models\CreditLot[]
     */
    public function creditLots(?int $customerId = null): array
    {
        $customerId ??= Craft::$app->getUser()->getId();

        if ($customerId === null || !Plugin::getInstance()->getSettings()->getEffectiveCreditEnabled()) {
            return [];
        }

        return Plugin::getInstance()->wallet->getLots((int)$customerId);
    }

    /**
     * A return by its portal token, for a merchant rendering their own status page.
     */
    public function returnByToken(string $token): ?ReturnRequest
    {
        $return = Plugin::getInstance()->returns->getReturnByToken($token);

        return $return !== null
            && $return->portalToken !== null
            && hash_equals($return->portalToken, $token)
            ? $return
            : null;
    }

    public function portalUrl(): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $prefix = trim($settings->portalUriPrefix, '/');

        return $settings->portalEnabled && $prefix !== ''
            ? \craft\helpers\UrlHelper::siteUrl($prefix)
            : null;
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    /**
     * @return \justinholtweb\boomerang\models\Settings
     */
    public function settings(): \justinholtweb\boomerang\models\Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
