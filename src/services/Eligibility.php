<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use DateInterval;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\models\EligibleLine;
use justinholtweb\boomerang\models\Eligibility as EligibilityModel;
use justinholtweb\boomerang\models\Settings;
use justinholtweb\boomerang\Plugin;

/**
 * Whether an order can be returned, and which of it.
 *
 * **The invariant: `evaluate()` is the only place this is decided.** The portal, the CP, the
 * console and the front-end API all read the one verdict, so the sentence a customer is shown is
 * the same sentence the merchant sees on the order. Everything else in here is a private helper
 * feeding it.
 *
 * Memoized per order for the request — a portal page asks once for the form and again for every
 * line it renders.
 */
class Eligibility extends Component
{
    /** @var array<string, EligibilityModel> */
    private array $_verdicts = [];

    /**
     * @param Order $order
     * @param int|null $excludeReturnId An RMA whose own claims should not count against the order,
     *                                  so that editing a return does not make its own lines
     *                                  ineligible.
     */
    public function evaluate(Order $order, ?int $excludeReturnId = null): EligibilityModel
    {
        $key = sprintf('%d:%s', (int)$order->id, $excludeReturnId ?? '-');

        if (isset($this->_verdicts[$key])) {
            return $this->_verdicts[$key];
        }

        $settings = Plugin::getInstance()->getSettings();

        $verdict = new EligibilityModel();
        $verdict->setOrder($order);

        $this->checkOrder($verdict, $order, $settings);
        $this->checkWindow($verdict, $order, $settings);
        $this->checkExistingReturns($verdict, $order, $settings, $excludeReturnId);
        $this->buildLines($verdict, $order, $settings, $excludeReturnId);

        // The order is returnable when nothing refused it outright and at least one line has
        // something left on it. Both halves matter: an order inside its window whose every line
        // has already come back is not returnable, and saying so is more useful than an empty
        // form.
        $verdict->isEligible = $verdict->messages === [] && $verdict->getEligibleLines() !== [];

        if ($verdict->messages === [] && $verdict->getEligibleLines() === []) {
            $verdict->messages[] = Craft::t('boomerang', 'Everything on this order has already been returned.');
            $verdict->codes[] = 'nothingLeft';
        }

        return $this->_verdicts[$key] = $verdict;
    }

    /**
     * Drop the memoized verdicts.
     *
     * The memo is per request and per order, which is right for a page that asks about the same
     * order five times — but wrong for anything that changes the order, the settings or the
     * returns against it and then asks again in the same process: a console command, a queue job,
     * a test suite. Those call this.
     */
    public function forget(?int $orderId = null): void
    {
        if ($orderId === null) {
            $this->_verdicts = [];

            return;
        }

        foreach (array_keys($this->_verdicts) as $key) {
            if (str_starts_with((string)$key, $orderId . ':')) {
                unset($this->_verdicts[$key]);
            }
        }
    }

    /**
     * Convenience for the common question, without the trace.
     */
    public function isEligible(Order $order): bool
    {
        return $this->evaluate($order)->isEligible;
    }

    /**
     * The order an emailed or typed-in "order number + email" pair refers to.
     *
     * Both halves are required and the email is compared case-insensitively but exactly — a
     * lookup that accepts the number alone is a lookup that hands anybody somebody else's address
     * and shopping habits.
     */
    public function findOrderForLookup(string $number, string $email): ?Order
    {
        $number = trim($number);
        $email = trim($email);

        if ($number === '' || $email === '') {
            return null;
        }

        $query = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->limit(1);

        // Customers quote the short reference from their receipt far more often than the 32
        // character number, so both are accepted.
        if (strlen($number) >= 32) {
            $query->number($number);
        } else {
            $query->reference($number);
        }

        $order = $query->one();

        if ($order === null) {
            return null;
        }

        return strcasecmp(trim((string)$order->email), $email) === 0 ? $order : null;
    }

    /**
     * How many units of a line item are already claimed by other RMAs.
     *
     * Rejected and cancelled returns do not count — a refused request must not lock the goods up
     * forever, which is the bug every hand-rolled returns table has.
     *
     * @return array<int, int> lineItemId => qty
     */
    public function getClaimedQuantities(int $orderId, ?int $excludeReturnId = null): array
    {
        $closedIds = $this->deadStateIds();

        $query = (new Query())
            ->select([
                'lineItemId' => 'bri.lineItemId',
                'qty' => 'SUM([[bri.qtyRequested]])',
            ])
            ->from(['bri' => Table::RETURNITEMS])
            ->innerJoin(['br' => Table::RETURNS], '[[br.id]] = [[bri.returnId]]')
            ->where(['br.orderId' => $orderId])
            ->andWhere(['not', ['bri.lineItemId' => null]])
            ->groupBy(['bri.lineItemId']);

        if ($closedIds !== []) {
            $query->andWhere(['or', ['br.stateId' => null], ['not', ['br.stateId' => $closedIds]]]);
        }

        if ($excludeReturnId !== null) {
            $query->andWhere(['not', ['bri.returnId' => $excludeReturnId]]);
        }

        $claimed = [];

        foreach ($query->all() as $row) {
            $claimed[(int)$row['lineItemId']] = (int)$row['qty'];
        }

        return $claimed;
    }

    // Order-level checks
    // -------------------------------------------------------------------------

    private function checkOrder(EligibilityModel $verdict, Order $order, Settings $settings): void
    {
        if ($settings->requireCompletedOrder && !$order->isCompleted) {
            $verdict->messages[] = Craft::t('boomerang', 'This order hasn’t been completed yet.');
            $verdict->codes[] = 'notCompleted';
        }

        if ($settings->requirePaidOrder && $order->getTotalPaid() <= 0) {
            $verdict->messages[] = Craft::t('boomerang', 'This order hasn’t been paid for.');
            $verdict->codes[] = 'notPaid';
        }

        if ($settings->eligibleOrderStatusUids !== []) {
            $status = $order->getOrderStatus();

            if ($status === null || !in_array($status->uid, $settings->eligibleOrderStatusUids, true)) {
                $verdict->messages[] = Craft::t('boomerang', 'Orders with this status can’t be returned.');
                $verdict->codes[] = 'statusNotEligible';
            }
        }
    }

    private function checkWindow(EligibilityModel $verdict, Order $order, Settings $settings): void
    {
        if ($settings->returnWindowDays < 1) {
            return;
        }

        $start = $this->windowStartDate($order, $settings);

        if ($start === null) {
            // No date to count from means no window to be outside of. A window that silently
            // never opens is worse than a generous one.
            return;
        }

        $closes = (clone $start)->add(new DateInterval('P' . $settings->returnWindowDays . 'D'));
        $now = new DateTime();

        $verdict->windowClosesAt = $closes;
        $verdict->daysRemaining = (int)$now->diff($closes)->format('%r%a');

        if ($now > $closes) {
            $verdict->messages[] = Craft::t('boomerang', 'The {days}-day return window for this order closed on {date}.', [
                'days' => $settings->returnWindowDays,
                'date' => Craft::$app->getFormatter()->asDate($closes, 'long'),
            ]);
            $verdict->codes[] = 'windowClosed';
        }
    }

    private function windowStartDate(Order $order, Settings $settings): ?DateTime
    {
        if ($settings->windowStartsFrom === Settings::WINDOW_FROM_CUSTOM && $settings->windowStartsFromField !== '') {
            $value = null;

            try {
                $value = $order->{$settings->windowStartsFromField};
            } catch (\Throwable) {
                // A handle that no longer resolves falls through to the order date below rather
                // than throwing on a customer-facing page.
            }

            if ($value instanceof DateTime) {
                return $value;
            }
        }

        if ($settings->windowStartsFrom === Settings::WINDOW_FROM_PAID && $order->datePaid !== null) {
            return $order->datePaid;
        }

        return $order->dateOrdered ?? $order->dateCreated;
    }

    private function checkExistingReturns(EligibilityModel $verdict, Order $order, Settings $settings, ?int $excludeReturnId): void
    {
        if ($settings->allowMultipleReturns) {
            return;
        }

        $closedIds = $this->deadStateIds();

        $query = (new Query())
            ->from([Table::RETURNS])
            ->where(['orderId' => $order->id]);

        if ($closedIds !== []) {
            $query->andWhere(['or', ['stateId' => null], ['not', ['stateId' => $closedIds]]]);
        }

        if ($excludeReturnId !== null) {
            $query->andWhere(['not', ['id' => $excludeReturnId]]);
        }

        if ((int)$query->count() > 0) {
            $verdict->messages[] = Craft::t('boomerang', 'There is already a return in progress for this order.');
            $verdict->codes[] = 'returnInProgress';
        }
    }

    // Line-level checks
    // -------------------------------------------------------------------------

    private function buildLines(EligibilityModel $verdict, Order $order, Settings $settings, ?int $excludeReturnId): void
    {
        $claimed = $order->id !== null ? $this->getClaimedQuantities($order->id, $excludeReturnId) : [];
        $excludedTypeIds = $this->excludedProductTypeIds($settings);

        foreach ($order->getLineItems() as $lineItem) {
            $line = $this->buildLine($lineItem, $claimed, $excludedTypeIds, $settings);
            $verdict->lines[(int)$lineItem->id] = $line;
        }
    }

    /**
     * @param array<int, int> $claimed
     * @param int[] $excludedTypeIds
     */
    private function buildLine(LineItem $lineItem, array $claimed, array $excludedTypeIds, Settings $settings): EligibleLine
    {
        $qty = max(1, $lineItem->qty);

        $line = new EligibleLine();
        $line->setLineItem($lineItem);
        $line->lineItemId = $lineItem->id;
        $line->purchasableId = $lineItem->purchasableId;
        $line->sku = $lineItem->getSku();
        $line->description = $lineItem->getDescription();
        $line->options = $lineItem->getOptions();
        $line->qtyOrdered = $lineItem->qty;
        $line->qtyReturned = $claimed[(int)$lineItem->id] ?? 0;
        $line->qtyAvailable = max(0, $lineItem->qty - $line->qtyReturned);

        // What was actually paid per unit for the goods. The discount is a negative adjustment, so
        // adding it is subtracting it — refunding the pre-discount price is how a store gives away
        // the value of its own promotion.
        $line->unitPrice = round(($lineItem->getSubtotal() + $lineItem->getDiscount()) / $qty, 4);

        // Only tax that was added on top. Tax included in the price is already inside unitPrice,
        // and counting it again refunds it twice.
        $line->unitTax = round($lineItem->getTax() / $qty, 4);
        $line->unitShipping = round($lineItem->getShippingCost() / $qty, 4);

        $line->isEligible = true;

        if ($line->qtyAvailable < 1) {
            $line->isEligible = false;
            $line->code = 'alreadyReturned';
            $line->message = Craft::t('boomerang', 'Already returned.');

            return $line;
        }

        if ($this->skuIsExcluded($line->sku, $settings->excludedSkus)) {
            $line->isEligible = false;
            $line->code = 'excludedSku';
            $line->message = Craft::t('boomerang', 'This item can’t be returned.');

            return $line;
        }

        if ($excludedTypeIds !== [] && $this->productTypeIdOf($lineItem) !== null
            && in_array($this->productTypeIdOf($lineItem), $excludedTypeIds, true)) {
            $line->isEligible = false;
            $line->code = 'excludedProductType';
            $line->message = Craft::t('boomerang', 'This item can’t be returned.');

            return $line;
        }

        if (!$settings->allowPartialQty && $line->qtyReturned > 0) {
            $line->isEligible = false;
            $line->code = 'partialNotAllowed';
            $line->message = Craft::t('boomerang', 'Part of this line has already been returned.');
        }

        return $line;
    }

    /**
     * Wildcard SKU matching, so a merchant can exclude `GIFT-*` without listing every card.
     *
     * @param string[] $patterns
     */
    private function skuIsExcluded(string $sku, array $patterns): bool
    {
        if ($sku === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern);

            if ($pattern === '') {
                continue;
            }

            if (fnmatch($pattern, $sku, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function excludedProductTypeIds(Settings $settings): array
    {
        if ($settings->excludedProductTypeUids === []) {
            return [];
        }

        $ids = [];

        foreach ($settings->excludedProductTypeUids as $uid) {
            $type = Commerce::getInstance()->getProductTypes()->getProductTypeByUid((string)$uid);

            if ($type !== null) {
                $ids[] = (int)$type->id;
            }
        }

        return $ids;
    }

    /**
     * A line item's product type, when it has one.
     *
     * Custom line items and purchasables from other plugins have no product type at all, which is
     * not the same as having one that is excluded.
     */
    private function productTypeIdOf(LineItem $lineItem): ?int
    {
        $purchasable = $lineItem->getPurchasable();

        if ($purchasable === null) {
            return null;
        }

        // `getProduct()` on a Commerce variant, `getOwner()` on anything else nested. Neither is
        // guaranteed: a custom line item or a purchasable from another plugin has no product type
        // at all, which is not the same as having one that is excluded.
        foreach (['getProduct', 'getOwner'] as $method) {
            if (!method_exists($purchasable, $method)) {
                continue;
            }

            $owner = $purchasable->$method();

            if ($owner !== null && isset($owner->typeId)) {
                return (int)$owner->typeId;
            }
        }

        return null;
    }

    /**
     * The states an RMA in which no longer holds a claim on the goods.
     *
     * @return int[]
     */
    private function deadStateIds(): array
    {
        $ids = [];

        foreach (Plugin::getInstance()->states->getAllStates() as $state) {
            if (in_array($state->getKind(), [StateKind::Rejected, StateKind::Cancelled], true)) {
                $ids[] = (int)$state->id;
            }
        }

        return $ids;
    }
}
