<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\db\Query;
use justinholtweb\boomerang\adjusters\ExchangeCredit;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\Plugin;
use RuntimeException;

/**
 * Exchange and replacement orders.
 *
 * An exchange is a **new Commerce order** carrying a credit worth the returned goods, not a
 * rewritten original. Editing the original order would falsify the accounts it has already been
 * counted in, break the refund it may still need, and leave the customer with a receipt that no
 * longer matches what they paid.
 *
 * ## How the money lands
 *
 * The credit is applied by {@see \justinholtweb\boomerang\adjusters\ExchangeCredit}, which runs
 * after tax and is recomputed on every recalculation, so a customer who adds another item to their
 * exchange gets the same credit against a bigger basket rather than a stale number. It is capped
 * at the order's total: an adjustment cannot take an order below zero, and a merchant does not
 * want an exchange order that owes the customer money.
 *
 * Any credit left over — a $60 return exchanged for a $40 item — is issued to the wallet when the
 * exchange order completes, not when it is created. At creation the basket is not final, and
 * credit issued against a guess is credit that has to be clawed back.
 */
class Exchanges extends Component
{
    /**
     * Build the exchange order for an RMA.
     *
     * `$lines` is `[['purchasableId' => 12, 'qty' => 1, 'options' => []], …]`. Empty means a
     * straight replacement: the same purchasables, in the same quantities, that came back.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    public function create(ReturnRequest $return, array $lines = []): Order
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new RuntimeException(Craft::t('boomerang', 'Exchange orders need Boomerang Pro.'));
        }

        if ($return->exchangeOrderId !== null) {
            $existing = $return->getExchangeOrder();

            if ($existing !== null) {
                // A retried transition. The order is already there.
                return $existing;
            }
        }

        if ($lines === []) {
            $lines = $this->replacementLines($return);
        }

        if ($lines === []) {
            throw new RuntimeException(Craft::t('boomerang', 'There is nothing to exchange — none of the returned items still exist as products.'));
        }

        $commerce = Commerce::getInstance();
        $source = $return->getOrder();

        $order = new Order();
        $order->number = $commerce->getCarts()->generateCartNumber();
        $order->storeId = $return->storeId ?? $source?->storeId;
        $order->orderSiteId = $return->submittedSiteId;
        $order->currency = $return->currency ?? $source?->currency;
        $order->setEmail($return->email);

        if ($return->customerId !== null) {
            $order->setCustomerId($return->customerId);
        }

        // The customer is not going to retype where they live. Copied as a plain attribute array,
        // never as the address element itself or its `toArray()`: Commerce refuses an address it
        // does not own — "Can not set a shipping address on the order that is not owned by the
        // order" — and `toArray()` carries the original's IDs, which is exactly what trips it.
        if ($source !== null) {
            $shipping = $this->addressAttributes($source->getShippingAddress());

            if ($shipping !== null) {
                $order->setShippingAddress($shipping);
            }

            $billing = $this->addressAttributes($source->getBillingAddress());

            if ($billing !== null) {
                $order->setBillingAddress($billing);
            }
        }

        if (!Craft::$app->getElements()->saveElement($order, false)) {
            throw new RuntimeException(Craft::t('boomerang', 'Could not create the exchange order.'));
        }

        foreach ($lines as $line) {
            $purchasableId = (int)($line['purchasableId'] ?? 0);
            $qty = max(1, (int)($line['qty'] ?? 1));

            if ($purchasableId < 1) {
                continue;
            }

            $lineItem = $commerce->getLineItems()->createLineItem(
                $order,
                $purchasableId,
                (array)($line['options'] ?? []),
                $qty,
            );

            $order->addLineItem($lineItem);
        }

        if (!Craft::$app->getElements()->saveElement($order, false)) {
            throw new RuntimeException(Craft::t('boomerang', 'Could not add items to the exchange order.'));
        }

        $return->exchangeOrderId = (int)$order->id;
        Plugin::getInstance()->returns->save($return, false);

        Plugin::getInstance()->returns->addEvent($return, EventType::ExchangeCreated, null, [
            'orderId' => $order->id,
            'orderNumber' => $order->number,
            'credit' => $return->resolutionAmount,
            'lines' => count($lines),
        ]);

        return $order;
    }

    /**
     * An address as plain attributes Commerce will happily build a new owned element from.
     *
     * @return array<string, mixed>|null
     */
    private function addressAttributes(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $attributes = [];

        foreach ([
            'title', 'fullName', 'firstName', 'lastName', 'organization', 'organizationTaxId',
            'addressLine1', 'addressLine2', 'addressLine3', 'locality', 'dependentLocality',
            'administrativeArea', 'postalCode', 'sortingCode', 'countryCode',
        ] as $attribute) {
            $value = $address->$attribute ?? null;

            if ($value !== null && $value !== '') {
                $attributes[$attribute] = $value;
            }
        }

        return $attributes !== [] ? $attributes : null;
    }

    /**
     * The credit an exchange order is entitled to, and what it can actually absorb.
     *
     * Read by the adjuster on every recalculation, so it is deliberately cheap and does not
     * reach for the RMA element — the sub-table has everything it needs.
     *
     * @return array{returnId: int, reference: string, credit: float}|null
     */
    public function getCreditForOrder(int $orderId): ?array
    {
        $row = (new Query())
            ->select(['id', 'reference', 'resolutionAmount', 'resolution'])
            ->from([Table::RETURNS])
            ->where(['exchangeOrderId' => $orderId])
            ->one();

        if ($row === null) {
            return null;
        }

        $resolution = Resolution::tryFrom((string)$row['resolution']);

        if ($resolution !== Resolution::Exchange && $resolution !== Resolution::Replacement) {
            return null;
        }

        return [
            'returnId' => (int)$row['id'],
            'reference' => (string)$row['reference'],
            'credit' => round((float)$row['resolutionAmount'], 2),
        ];
    }

    /**
     * Issue whatever credit the exchange order could not absorb.
     *
     * Called when the exchange order completes. Idempotent on the RMA, so a re-completed order —
     * or a merchant marking one complete by hand a second time — does not double the credit.
     */
    public function settleRemainder(Order $order): float
    {
        $credit = $this->getCreditForOrder((int)$order->id);

        if ($credit === null) {
            return 0.0;
        }

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->getEffectiveCreditEnabled()) {
            return 0.0;
        }

        $applied = 0.0;

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->type === ExchangeCredit::ADJUSTMENT_TYPE) {
                $applied += abs($adjustment->amount);
            }
        }

        $remainder = round($credit['credit'] - $applied, 2);

        if ($remainder <= 0.009) {
            return 0.0;
        }

        $return = Craft::$app->getElements()->getElementById($credit['returnId'], ReturnRequest::class);

        if ($return === null || $return->customerId === null) {
            return 0.0;
        }

        Plugin::getInstance()->wallet->issue($return->customerId, $remainder, [
            'storeId' => $order->storeId,
            'currency' => $order->currency,
            'returnId' => $return->id,
            'note' => Craft::t('boomerang', 'Balance of exchange for {reference}', ['reference' => $return->reference]),
            'idempotencyKey' => sprintf('return:%d:exchange-remainder', $return->id),
        ]);

        $return->creditedAmount = round($return->creditedAmount + $remainder, 2);
        Plugin::getInstance()->returns->save($return, false);

        Plugin::getInstance()->returns->addEvent($return, EventType::CreditIssued, Craft::t('boomerang', 'Balance left over from the exchange.'), [
            'amount' => $remainder,
            'orderId' => $order->id,
        ]);

        return $remainder;
    }

    /**
     * The same goods again, for a straight replacement.
     *
     * Items whose purchasable no longer exists are skipped rather than failing the whole exchange:
     * a merchant sending two of three replacements is better served than one who cannot send any.
     *
     * @return array<int, array<string, mixed>>
     */
    private function replacementLines(ReturnRequest $return): array
    {
        $lines = [];

        foreach ($return->getItems() as $item) {
            if ($item->purchasableId === null || $item->getPurchasable() === null) {
                continue;
            }

            $lines[] = [
                'purchasableId' => $item->purchasableId,
                'qty' => $item->getResolvableQty(),
                'options' => $item->options,
            ];
        }

        return $lines;
    }
}
