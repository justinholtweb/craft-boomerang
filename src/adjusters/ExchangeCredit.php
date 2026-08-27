<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use justinholtweb\boomerang\Plugin;

/**
 * The credit an exchange order carries for the goods that came back.
 *
 * Registered last, so it runs after shipping, discounts and tax and applies to the grand total —
 * which is what a customer means by "I'm sending back the $60 one and taking the $45 one".
 *
 * ## It is capped, and the cap matters
 *
 * A Commerce adjustment can drive an order's total below zero, which produces an order the store
 * appears to owe money on and a payment gateway that refuses to process it. The credit is
 * therefore capped at what is left of the order, and any remainder is issued to the customer's
 * wallet when the exchange order completes — see
 * {@see \justinholtweb\boomerang\services\Exchanges::settleRemainder()}.
 *
 * The subject of the cap is built from the order's component totals rather than from
 * `getTotalPrice()`, which would already include this adjuster's own output from the previous
 * pass. An adjuster that reads its own result converges on zero over successive recalculations,
 * and the symptom — an exchange credit that shrinks every time the cart is touched — takes a
 * long time to attribute to the right cause.
 */
class ExchangeCredit extends Component implements AdjusterInterface
{
    public const ADJUSTMENT_TYPE = 'boomerangExchangeCredit';

    public function adjust(Order $order): array
    {
        if ($order->id === null || !Plugin::getInstance()->isPro()) {
            return [];
        }

        $credit = Plugin::getInstance()->exchanges->getCreditForOrder((int)$order->id);

        if ($credit === null || $credit['credit'] <= 0) {
            return [];
        }

        // `getTotalDiscount()` is already negative, so adding it subtracts it.
        $subject = round(
            $order->getItemSubtotal()
            + $order->getTotalTax()
            + $order->getTotalShippingCost()
            + $order->getTotalDiscount(),
            2,
        );

        $amount = round(min($credit['credit'], max(0.0, $subject)), 2);

        if ($amount <= 0) {
            return [];
        }

        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = Craft::t('boomerang', 'Return credit');
        $adjustment->description = Craft::t('boomerang', 'Credit for return {reference}', ['reference' => $credit['reference']]);
        $adjustment->setOrder($order);
        $adjustment->amount = -$amount;
        $adjustment->sourceSnapshot = [
            'returnId' => $credit['returnId'],
            'reference' => $credit['reference'],
            'entitled' => $credit['credit'],
            'applied' => $amount,
        ];

        return [$adjustment];
    }
}
