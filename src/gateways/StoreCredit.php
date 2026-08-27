<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\web\Response as WebResponse;
use justinholtweb\boomerang\models\responses\CreditResponse;
use justinholtweb\boomerang\Plugin;
use RuntimeException;

/**
 * Store credit, as a payment method.
 *
 * A gateway rather than a discount or an adjuster, because that is what it *is*: the customer sees
 * "Store credit — $40.00 available" beside the card at checkout, the merchant sees a real
 * transaction on the order, and every refund path Commerce already has works on it unchanged.
 * Modelling a wallet as a discount produces an order whose subtotal lies and whose refunds cannot
 * be reconciled against anything.
 *
 * ## Partial payment is the normal case
 *
 * A $40 balance against a $95 order should pay $40 and leave the card to pay $55, so
 * `supportsPartialPayment()` is true and the amount charged is whatever Commerce put on the
 * transaction. Commerce takes that from `Order::getPaymentAmount()`, so a template posting
 * `paymentAmount` gets exactly what it asked for.
 *
 * ## Authorize and purchase are both real
 *
 * A merchant who has set their gateways to authorize-then-capture should not have store credit
 * behave differently to their card gateway. `authorize()` allocates a reversible **hold** against
 * the customer's lots — the money is unspendable but not spent — and `capture()` retypes those
 * exact rows rather than reallocating, because a capture that re-drew could land on different lots
 * than the authorization did, and a lot that expired in between would silently vanish.
 *
 * ## The idempotency key
 *
 * Commerce generates a transaction's hash in its constructor and its ID only when it saves it,
 * which happens *after* the gateway has run. The hash is therefore the only identifier available
 * at the moment the wallet moves, and it is what every entry is written and later matched against.
 *
 * ## On Lite
 *
 * The type stays registered on every edition. Unregistering it would turn a merchant's configured
 * gateway into Commerce's `MissingGateway` the moment a licence lapsed, which breaks the orders
 * that were paid with it. Instead `availableForUseWithOrder()` returns false, so nobody can pay
 * with it and everything already paid stays readable and refundable.
 */
class StoreCredit extends Gateway
{
    public static function displayName(): string
    {
        return Craft::t('boomerang', 'Store Credit (Boomerang)');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('boomerang/gateways/store-credit-settings', [
            'gateway' => $this,
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        $order = $params['order'] ?? null;
        $customerId = $order instanceof Order ? $order->getCustomerId() : Craft::$app->getUser()->getId();

        return Craft::$app->getView()->renderTemplate('boomerang/gateways/store-credit-payment', [
            'gateway' => $this,
            'order' => $order,
            'balance' => $customerId !== null
                ? Plugin::getInstance()->wallet->getBalance((int)$customerId, $order?->storeId, $order?->currency)
                : 0.0,
            'params' => $params,
        ]);
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
            'authorize' => Craft::t('commerce', 'Authorize Only (Manually Capture)'),
        ];
    }

    /**
     * Offered only to a signed-in customer who has enough credit to be worth showing.
     */
    public function availableForUseWithOrder(Order $order): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->getEffectiveCreditEnabled()) {
            return false;
        }

        $customerId = $order->getCustomerId();

        if ($customerId === null) {
            return false;
        }

        $balance = $plugin->wallet->getBalance((int)$customerId, $order->storeId, $order->currency);

        if ($balance <= 0 || $balance < $plugin->getSettings()->creditMinimumSpend) {
            return false;
        }

        return parent::availableForUseWithOrder($order);
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->draw($transaction, hold: true);
    }

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->draw($transaction, hold: false);
    }

    /**
     * Turn the authorization's hold into a spend.
     *
     * `$transaction` is Commerce's *child* capture transaction, so the rows to retype belong to
     * its parent.
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $parentHash = $this->parentHash($transaction);

        if ($parentHash === null) {
            return new CreditResponse(false, '', Craft::t('boomerang', 'The authorization this capture belongs to could not be found.'));
        }

        $captured = Plugin::getInstance()->wallet->captureHolds($parentHash);

        if ($captured < 1) {
            // Already captured, or nothing was held. Neither is a failure: a replayed capture must
            // not fail an order that is genuinely paid.
            return new CreditResponse(true, $parentHash, Craft::t('boomerang', 'Nothing left to capture.'));
        }

        return new CreditResponse(true, $parentHash, '', ['entries' => $captured]);
    }

    /**
     * Put a wallet payment back into the lots it came from.
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $parentHash = $this->parentHash($transaction);

        if ($parentHash === null) {
            return new CreditResponse(false, '', Craft::t('boomerang', 'The payment this refund belongs to could not be found.'));
        }

        $amount = (float)$transaction->paymentAmount;

        $returned = Plugin::getInstance()->wallet->refundToWallet($parentHash, $amount, [
            'orderId' => $transaction->orderId,
            'transactionId' => $transaction->parentId,
            'note' => Craft::t('boomerang', 'Refund on order {number}', [
                'number' => $transaction->getOrder()?->reference ?? $transaction->orderId,
            ]),
        ]);

        if ($returned <= 0) {
            return new CreditResponse(false, $parentHash, Craft::t('boomerang', 'There is no store credit payment left to refund on this order.'));
        }

        return new CreditResponse(true, $parentHash, '', ['refunded' => $returned]);
    }

    // Capabilities
    // -------------------------------------------------------------------------

    public function supportsAuthorize(): bool
    {
        return true;
    }

    public function supportsCapture(): bool
    {
        return true;
    }

    public function supportsPurchase(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsPartialRefund(): bool
    {
        return true;
    }

    public function supportsPartialPayment(): bool
    {
        return true;
    }

    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    public function supportsPaymentSources(): bool
    {
        return false;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function processWebHook(): WebResponse
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Take the money out of the wallet, as a hold or as a spend.
     */
    private function draw(Transaction $transaction, bool $hold): RequestResponseInterface
    {
        $plugin = Plugin::getInstance();
        $order = $transaction->getOrder();

        if ($order === null) {
            return new CreditResponse(false, '', Craft::t('boomerang', 'This transaction has no order.'));
        }

        if (!$plugin->getSettings()->getEffectiveCreditEnabled()) {
            return new CreditResponse(false, '', Craft::t('boomerang', 'Store credit is not available on this site.'));
        }

        $customerId = $order->getCustomerId();

        if ($customerId === null) {
            return new CreditResponse(false, '', Craft::t('boomerang', 'Store credit needs a customer account.'));
        }

        // The base-currency amount, because that is the currency the wallet holds.
        $amount = round((float)$transaction->amount, 2);

        if ($amount <= 0) {
            return new CreditResponse(false, (string)$transaction->hash, Craft::t('boomerang', 'There is nothing to pay.'));
        }

        $context = [
            'orderId' => $order->id,
            'storeId' => $order->storeId,
            'currency' => $transaction->currency,
            'transactionHash' => $transaction->hash,
            'note' => Craft::t('boomerang', 'Order {number}', ['number' => $order->reference ?: $order->getShortNumber()]),
        ];

        $key = sprintf('%s:%s', $hold ? 'hold' : 'spend', $transaction->hash);

        try {
            $entries = $hold
                ? $plugin->wallet->hold((int)$customerId, $amount, $key, $context)
                : $plugin->wallet->spend((int)$customerId, $amount, $key, $context);
        } catch (RuntimeException $e) {
            // An insufficient balance is a customer-facing message, not an exception page: two
            // tabs, or a slow checkout while credit expired.
            return new CreditResponse(false, (string)$transaction->hash, $e->getMessage());
        }

        if ($entries === []) {
            return new CreditResponse(false, (string)$transaction->hash, Craft::t('boomerang', 'No store credit could be applied.'));
        }

        return new CreditResponse(true, (string)$transaction->hash, '', [
            'entries' => count($entries),
            'amount' => $amount,
        ]);
    }

    /**
     * The hash of the transaction a child transaction was made against.
     */
    private function parentHash(Transaction $transaction): ?string
    {
        if ($transaction->parentId === null) {
            return $transaction->hash;
        }

        $parent = Commerce::getInstance()->getTransactions()->getTransactionById((int)$transaction->parentId);

        return $parent?->hash;
    }
}
