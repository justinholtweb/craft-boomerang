<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\Plugin;
use RuntimeException;

/**
 * Giving the money back.
 *
 * **The invariant: `issue()` is the only place money leaves.** It is also the one call in the
 * plugin that fails *loudly* — a refused refund aborts the transition and leaves the RMA exactly
 * where it was, rather than marking it resolved with nothing having moved.
 *
 * ## Partial success is recorded before it is reported
 *
 * An order can be paid across several transactions — a card, a gift card, store credit — and each
 * has its own refundable ceiling. If the third of four gateways refuses, the first two have
 * already moved real money. `issue()` writes `refundedAmount` to the RMA after *every* successful
 * refund, before it throws, so the RMA's ledger is right even when the operation as a whole is
 * not. Anything else would leave a merchant reconciling a refund the plugin denies making.
 */
class Refunds extends Component
{
    /**
     * Refund an RMA's resolution amount across the order's transactions.
     *
     * @param float|null $amount Overrides the RMA's own resolution amount.
     * @return float The amount refunded.
     * @throws RuntimeException when the full amount could not be refunded.
     */
    public function issue(ReturnRequest $return, ?float $amount = null): float
    {
        $order = $return->getOrder();

        if ($order === null) {
            throw new RuntimeException(Craft::t('boomerang', 'This return’s order no longer exists, so there is nothing to refund against.'));
        }

        $target = round($amount ?? $return->resolutionAmount, 2);

        if ($target <= 0) {
            return 0.0;
        }

        $alreadyRefunded = round($return->refundedAmount, 2);

        if ($alreadyRefunded >= $target) {
            // A retried transition. The money has already gone.
            return 0.0;
        }

        $remaining = round($target - $alreadyRefunded, 2);
        $refunded = 0.0;
        $note = Craft::t('boomerang', 'Return {reference}', ['reference' => $return->reference]);

        foreach ($this->refundableTransactions($order->id) as $transaction) {
            if ($remaining <= 0) {
                break;
            }

            $ceiling = round(Commerce::getInstance()->getTransactions()->refundableAmountForTransaction($transaction), 2);
            $portion = round(min($ceiling, $remaining), 2);

            if ($portion <= 0) {
                continue;
            }

            $child = Commerce::getInstance()->getPayments()->refundTransaction($transaction, $portion, $note);

            if ($child->status !== TransactionRecord::STATUS_SUCCESS) {
                // Recorded, not swallowed: the merchant needs to know which gateway said no.
                Plugin::getInstance()->returns->addEvent($return, EventType::Failed, Craft::t('boomerang', 'Refund of {amount} through {gateway} was not successful: {message}', [
                    'amount' => $portion,
                    'gateway' => $transaction->getGateway()?->name ?? $transaction->gatewayId,
                    'message' => $child->message ?: Craft::t('boomerang', 'no reason given'),
                ]), ['transactionId' => $transaction->id]);

                continue;
            }

            $refunded = round($refunded + $portion, 2);
            $remaining = round($remaining - $portion, 2);

            // Written now, not at the end. If a later gateway refuses, this much has still gone.
            $return->refundedAmount = round($alreadyRefunded + $refunded, 2);
            Plugin::getInstance()->returns->save($return, false);

            Plugin::getInstance()->returns->addEvent($return, EventType::Refunded, null, [
                'amount' => $portion,
                'gateway' => $transaction->getGateway()?->name,
                'transactionId' => $transaction->id,
                'childTransactionId' => $child->id,
                'reference' => $child->reference,
            ]);
        }

        if ($remaining > 0.009) {
            throw new RuntimeException(Craft::t('boomerang', 'Only {refunded} of {target} could be refunded — the order’s transactions have {remaining} left unrefundable. Refund the balance manually, or resolve this return as store credit.', [
                'refunded' => $refunded,
                'target' => $target,
                'remaining' => $remaining,
            ]));
        }

        return $refunded;
    }

    /**
     * What could still be refunded against an order, across every transaction.
     */
    public function getRefundableAmount(int $orderId): float
    {
        $total = 0.0;

        foreach ($this->refundableTransactions($orderId) as $transaction) {
            $total += Commerce::getInstance()->getTransactions()->refundableAmountForTransaction($transaction);
        }

        return round($total, 2);
    }

    /**
     * The order's refundable transactions, largest ceiling first.
     *
     * Largest first so that a refund crosses as few gateways as possible: every gateway hop is a
     * separate line on the customer's statement and a separate thing that can fail.
     *
     * @return Transaction[]
     */
    public function refundableTransactions(int $orderId): array
    {
        $transactions = array_filter(
            Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId($orderId),
            fn(Transaction $transaction) => Commerce::getInstance()->getTransactions()->canRefundTransaction($transaction),
        );

        usort(
            $transactions,
            fn(Transaction $a, Transaction $b) => Commerce::getInstance()->getTransactions()->refundableAmountForTransaction($b)
                <=> Commerce::getInstance()->getTransactions()->refundableAmountForTransaction($a),
        );

        return array_values($transactions);
    }
}
