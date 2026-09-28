<?php
/**
 * Fixtures and harness workarounds shared by Boomerang's test scripts.
 *
 * Required by `checks.php` and `portal.php` after Craft has booted and `check()` exists. Builds
 * real Commerce products, orders and payments, because eligibility, refunds and restocking all
 * depend on Commerce's own records rather than on anything a mock could stand in for.
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryTransactionType;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;

// Plugins are loaded lazily in a bare console bootstrap, so `Plugin::getInstance()` is null until
// something asks the plugins service for anything. Asking explicitly beats depending on the order
// the rest of the file happens to touch things in.
Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();

if ($plugin === null) {
    fwrite(STDERR, "Boomerang is not loaded — is it installed and enabled in this harness?\n");

    exit(1);
}
$originalEdition = $plugin->edition;
$storeId = $commerce->getStores()->getPrimaryStore()->id;

// In memory, not through project config. Project config is contended in this shared harness —
// the queue runner and a dozen sibling plugins write it while a long console script runs — and a
// suite that persists its own edition either fails on a BusyResourceException or leaves the
// harness on the wrong edition when it does.
$plugin->edition = Plugin::EDITION_PRO;

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Boomerang; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

// `craft-lyfe` (another sibling in this shared harness) hooks `EVENT_AFTER_COMPLETE_ORDER` and
// reads a `phone` custom field that does not exist here, so **completing any order** throws. Its
// handler is detached for this run — along with every other handler on that event, because Yii
// cannot remove one by owner — and Boomerang's own is re-attached immediately, since the exchange
// remainder is one of the things under test.
if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);

    yii\base\Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(yii\base\Event $event) use ($plugin) {
        $plugin->exchanges->settleRemainder($event->sender);
    });

    echo "  ! detached craft-lyfe's order-complete handler for this run\n";
}

$createdProducts = [];
$createdOrders = [];
$createdReturns = [];
$createdUsers = [];
$createdGatewayId = null;
$createdStates = [];
$createdReasons = [];

/**
 * Change settings for the duration of the run, in memory only.
 *
 * Never through `savePluginSettings()`: project config is contended in this shared harness, and a
 * suite that writes it fails on a `BusyResourceException` that has nothing to do with the plugin.
 * Everything under test reads `$plugin->getSettings()`, so an in-memory change exercises exactly
 * the same branches.
 */
function applySettings(array $values): void
{
    global $plugin;

    $plugin->getSettings()->setAttributes($values, false);
}

function makeProduct(string $sku, float $price, bool $tracked = true, int $stock = 10): Variant
{
    global $createdProducts, $commerce;

    $type = $commerce->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Boomerang fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = 1.5;
    $variant->isDefault = true;
    $variant->inventoryTracked = $tracked;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    $variant = Variant::find()->sku($sku)->one();

    if ($tracked) {
        $commerce->getInventory()->updatePurchasableInventoryLevel($variant, $stock);
    }

    return $variant;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true, ?int $customerId = null, string $email = 'boomerang-fixture@example.com'): Order
{
    global $createdOrders, $storeId, $commerce;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = $commerce->getCarts()->generateCartNumber();
    $order->setEmail($email);

    if ($customerId !== null) {
        $order->setCustomerId($customerId);
    }

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = $commerce->getLineItems()->createLineItem($order, $line['variant']->id, [], $line['qty']);
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array and
    // Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * A successful purchase transaction against the Dummy gateway, so the order is refundable.
 */
function payOrder(Order $order): craft\commerce\models\Transaction
{
    global $commerce;

    $gateway = null;

    foreach ($commerce->getGateways()->getAllGateways() as $candidate) {
        if ($candidate instanceof craft\commerce\gateways\Dummy) {
            $gateway = $candidate;

            break;
        }
    }

    if ($gateway === null) {
        throw new RuntimeException('No dummy gateway in this harness to pay with.');
    }

    $order->gatewayId = $gateway->id;
    Craft::$app->getElements()->saveElement($order, false);

    $transaction = $commerce->getTransactions()->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
    $transaction->status = TransactionRecord::STATUS_SUCCESS;
    $transaction->reference = 'boomerang-test-' . uniqid('', false);
    $transaction->amount = $order->getTotalPrice();
    $transaction->paymentAmount = $order->getTotalPrice();

    if (!$commerce->getTransactions()->saveTransaction($transaction)) {
        throw new RuntimeException('Could not save the fixture payment.');
    }

    $order->updateOrderPaidInformation();

    return $transaction;
}

function makeCustomer(string $email): User
{
    global $createdUsers;

    $user = new User();
    $user->email = $email;
    $user->username = $email;
    $user->firstName = 'Boomerang';
    $user->lastName = 'Fixture';
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user, false)) {
        throw new RuntimeException('Could not save fixture user: ' . json_encode($user->getErrors()));
    }

    $createdUsers[] = $user;

    return $user;
}

/**
 * @param array<int, array{lineItemId: int, qty: int, reason: string}> $lines
 */
function makeReturn(Order $order, array $lines, array $attributes = []): ReturnRequest
{
    global $plugin, $createdReturns;

    $payload = [];

    foreach ($lines as $line) {
        $reason = $plugin->reasons->getReasonByHandle($line['reason'] ?? 'changedMind');

        $payload[$line['lineItemId']] = [
            'qty' => $line['qty'],
            'reasonId' => $reason?->id,
            'comment' => $line['comment'] ?? 'Fixture comment',
        ];
    }

    $return = $plugin->returns->create($order, $payload, $attributes);

    if (!$plugin->returns->submit($return)) {
        throw new RuntimeException('Could not submit return: ' . json_encode($return->getErrors()));
    }

    $createdReturns[] = $return;

    return $return;
}

function stockAt(Variant $variant, InventoryTransactionType $type): int
{
    global $commerce;

    $location = $commerce->getInventoryLocations()->getAllInventoryLocations()->first();

    return (int)(new craft\db\Query())
        ->from(craft\commerce\db\Table::INVENTORYTRANSACTIONS)
        ->where([
            'inventoryItemId' => $variant->inventoryItemId,
            'inventoryLocationId' => $location->id,
            'type' => $type->value,
        ])
        ->sum('[[quantity]]');
}
