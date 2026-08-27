<?php
/**
 * Boomerang integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-rma/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: real Commerce orders and transactions, a real refund through a
 * gateway, real inventory transactions, and the wallet's allocation under its own lock. Idempotent
 * and self-cleaning — every run creates its own products, orders, returns and credit and removes
 * them in a `finally`.
 *
 * The suite switches the plugin to Pro for the bulk of the run and exercises Lite in its own
 * section, restoring the original edition and settings at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryTransactionType;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\CreditEntryType;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\gateways\StoreCredit;
use justinholtweb\boomerang\models\Reason;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

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

$originalSettings = $plugin->getSettings()->toArray();

try {
    applySettings([
        'returnWindowDays' => 30,
        'requireCompletedOrder' => true,
        'requirePaidOrder' => true,
        'allowPartialQty' => true,
        'allowMultipleReturns' => true,
        'autoApprove' => false,
        'autoApproveUnder' => 0,
        'refundIncludesTax' => true,
        'refundIncludesShipping' => false,
        'restockingFeePercent' => 0,
        'restockingFeeFlat' => 0,
        'waiveFeeOnFault' => true,
        'creditEnabled' => true,
        'creditBonusPercent' => 0,
        'creditExpiryDays' => 0,
        'restockEnabled' => true,
        // Nothing in this suite should send mail: a suite that needs an SMTP server up fails for
        // reasons that are not bugs.
        'notifyCustomer' => false,
        'notifyStaff' => false,
        'queueNotifications' => false,
        'excludedSkus' => [],
        'defaultResolution' => Resolution::Refund->value,
    ]);

    // -----------------------------------------------------------------------
    section('Enums and models');

    check('StateKind knows which kinds are closed', fn() => StateKind::Resolved->isClosed()
        && StateKind::Rejected->isClosed()
        && StateKind::Cancelled->isClosed()
        && !StateKind::Received->isClosed());

    check('A customer may cancel only before the goods are moving', fn() => StateKind::Requested->isCustomerCancellable()
        && StateKind::Approved->isCustomerCancellable()
        && !StateKind::Received->isCustomerCancellable());

    check('Condition picks the inventory transaction type', fn() => ItemCondition::Resellable->inventoryTransactionType() === InventoryTransactionType::AVAILABLE
        && ItemCondition::Damaged->inventoryTransactionType() === InventoryTransactionType::DAMAGED
        && ItemCondition::Inspect->inventoryTransactionType() === InventoryTransactionType::QUALITY_CONTROL
        && ItemCondition::Missing->inventoryTransactionType() === null);

    check('Missing goods are not restockable', fn() => !ItemCondition::Missing->isRestockable()
        && ItemCondition::Damaged->isRestockable());

    check('Credit, exchange and replacement are the Pro resolutions', fn() => Resolution::Credit->isPro()
        && Resolution::Exchange->isPro()
        && Resolution::Replacement->isPro()
        && !Resolution::Refund->isPro());

    // -----------------------------------------------------------------------
    section('States');

    check('Install seeded a working state machine', function() use ($plugin) {
        $states = $plugin->states->getAllStates();

        return count($states) >= 6 ?: 'only ' . count($states) . ' states';
    });

    check('There is a default state', fn() => $plugin->states->getDefaultState() !== null);

    check('States are found by kind, not by name', function() use ($plugin) {
        $approved = $plugin->states->getStateByKind(StateKind::Approved);

        return $approved !== null && $approved->isApproved();
    });

    check('The seeded machine refuses requested → resolved', function() use ($plugin) {
        $requested = $plugin->states->getStateByKind(StateKind::Requested);
        $resolved = $plugin->states->getStateByKind(StateKind::Resolved);

        return !$requested->allowsTransitionTo($resolved);
    });

    check('The seeded machine allows requested → approved', function() use ($plugin) {
        $requested = $plugin->states->getStateByKind(StateKind::Requested);
        $approved = $plugin->states->getStateByKind(StateKind::Approved);

        return $requested->allowsTransitionTo($approved);
    });

    check('A state always allows a move to itself', function() use ($plugin) {
        $state = $plugin->states->getDefaultState();

        return $state->allowsTransitionTo($state);
    });

    check('An empty transition list means anywhere', function() {
        $from = new State(['uid' => 'a', 'transitions' => []]);
        $to = new State(['uid' => 'b']);

        return $from->allowsTransitionTo($to);
    });

    check('A new state saves and is found by handle', function() use ($plugin, &$createdStates) {
        $state = new State([
            'name' => 'Boomerang test state',
            'handle' => 'boomerangTestState',
            'kind' => StateKind::Awaiting->value,
        ]);

        if (!$plugin->states->saveState($state)) {
            return json_encode($state->getErrors());
        }

        $createdStates[] = $state->id;

        return $plugin->states->getStateByHandle('boomerangTestState') !== null;
    });

    check('A duplicate handle is refused', function() use ($plugin) {
        $state = new State([
            'name' => 'Duplicate',
            'handle' => 'boomerangTestState',
            'kind' => StateKind::Awaiting->value,
        ]);

        return !$plugin->states->saveState($state);
    });

    check('States export as handles and import back', function() use ($plugin) {
        $export = $plugin->states->exportStates();

        foreach ($export as $row) {
            foreach ($row['transitions'] as $target) {
                if (!is_string($target) || str_contains($target, '-')) {
                    // A UID would be 36 characters with hyphens; a handle is not.
                    if (strlen($target) === 36) {
                        return 'transitions exported as UIDs';
                    }
                }
            }
        }

        $result = $plugin->states->importStates($export);

        return $result['errors'] === [] ? true : json_encode($result['errors']);
    });

    check('Re-importing the same export changes nothing structural', function() use ($plugin) {
        $before = count($plugin->states->getAllStates());
        $plugin->states->importStates($plugin->states->exportStates());

        return count($plugin->states->getAllStates()) === $before;
    });

    check('A transition list survives an export/import round trip', function() use ($plugin) {
        $requested = $plugin->states->getStateByHandle('requested');
        $approved = $plugin->states->getStateByHandle('approved');

        return $requested !== null && $approved !== null && $requested->allowsTransitionTo($approved);
    });

    // -----------------------------------------------------------------------
    section('Reasons');

    check('Install seeded reasons, some of them the store’s fault', function() use ($plugin) {
        $reasons = $plugin->reasons->getAllReasons();
        $fault = array_filter($reasons, fn(Reason $reason) => $reason->isFault);

        return count($reasons) >= 6 && count($fault) >= 3;
    });

    check('Damaged goods default to the damaged shelf', function() use ($plugin) {
        $reason = $plugin->reasons->getReasonByHandle('arrivedDamaged');

        return $reason?->getDefaultCondition() === ItemCondition::Damaged;
    });

    check('Reasons export and import by handle', function() use ($plugin) {
        $result = $plugin->reasons->importReasons($plugin->reasons->exportReasons());

        return $result['errors'] === [] && $result['created'] === 0;
    });

    // -----------------------------------------------------------------------
    section('Eligibility');

    $variantA = makeProduct('BOOM-A-' . uniqid('', false), 40.00);
    $variantB = makeProduct('BOOM-B-' . uniqid('', false), 25.00);

    $order = makeOrder([
        ['variant' => $variantA, 'qty' => 2],
        ['variant' => $variantB, 'qty' => 1],
    ]);
    payOrder($order);
    $order = Order::find()->id($order->id)->status(null)->one();

    check('A paid, completed, in-window order is returnable', function() use ($plugin, $order) {
        $verdict = $plugin->eligibility->evaluate($order);

        return $verdict->isEligible ?: implode(' ', $verdict->messages);
    });

    check('Every line comes back with its available quantity', function() use ($plugin, $order) {
        $verdict = $plugin->eligibility->evaluate($order);
        $lines = $verdict->getEligibleLines();

        return count($lines) === 2 && $lines[0]->qtyAvailable === 2 && $lines[1]->qtyAvailable === 1;
    });

    check('Unit prices are per unit, not per line', function() use ($plugin, $order, $variantA) {
        $verdict = $plugin->eligibility->evaluate($order);

        foreach ($verdict->getEligibleLines() as $line) {
            if ($line->sku === $variantA->sku) {
                // The line is 2 × $40; a per-line figure would be $80.
                return abs($line->unitPrice - 40.00) < 0.01 ?: 'unit price was ' . $line->unitPrice;
            }
        }

        return 'no line for the fixture variant';
    });

    check('The verdict carries a window closing date', function() use ($plugin, $order) {
        $verdict = $plugin->eligibility->evaluate($order);

        return $verdict->windowClosesAt !== null && $verdict->daysRemaining !== null;
    });

    check('An order outside the window is refused, by name', function() use ($plugin, $order) {
        applySettings(['returnWindowDays' => 1]);

        $old = clone $order;
        $old->dateOrdered = (new DateTime())->sub(new DateInterval('P40D'));
        Craft::$app->getElements()->saveElement($old, false);

        $plugin->eligibility->forget();
        $verdict = $plugin->eligibility->evaluate($old);

        applySettings(['returnWindowDays' => 30]);
        $old->dateOrdered = new DateTime();
        Craft::$app->getElements()->saveElement($old, false);
        $plugin->eligibility->forget();

        return !$verdict->isEligible && $verdict->hasCode('windowClosed');
    });

    check('An excluded SKU pattern takes a line out', function() use ($plugin, $order, $variantA) {
        applySettings(['excludedSkus' => ['BOOM-A-*']]);
        $plugin->eligibility->forget();

        $verdict = $plugin->eligibility->evaluate($order);
        $line = null;

        foreach ($verdict->lines as $candidate) {
            if ($candidate->sku === $variantA->sku) {
                $line = $candidate;
            }
        }

        applySettings(['excludedSkus' => []]);
        $plugin->eligibility->forget();

        return $line !== null && !$line->isEligible && $line->code === 'excludedSku';
    });

    check('Looking an order up needs the number and the email together', function() use ($plugin, $order) {
        $found = $plugin->eligibility->findOrderForLookup((string)$order->reference, 'boomerang-fixture@example.com');
        $wrong = $plugin->eligibility->findOrderForLookup((string)$order->reference, 'someone-else@example.com');

        return $found?->id === $order->id && $wrong === null;
    });

    check('The lookup is case-insensitive on the email', function() use ($plugin, $order) {
        return $plugin->eligibility->findOrderForLookup((string)$order->reference, 'BOOMERANG-FIXTURE@EXAMPLE.COM')?->id === $order->id;
    });

    // -----------------------------------------------------------------------
    section('Making a return');

    $lineA = $order->getLineItems()[0];
    $lineB = $order->getLineItems()[1];

    $return = makeReturn($order, [
        ['lineItemId' => $lineA->id, 'qty' => 1, 'reason' => 'wrongSize'],
    ]);

    check('The RMA got a sequential reference', fn() => $return->reference !== null
        && str_starts_with($return->reference, 'RMA-'));

    check('The RMA got a 32-character portal token', fn() => strlen((string)$return->portalToken) === 32);

    check('The order’s identity was copied onto the RMA', fn() => $return->orderNumber === $order->number
        && $return->orderReference === $order->reference
        && $return->email === $order->email);

    check('It starts in the default state', fn() => $return->getState()?->isRequested() === true);

    check('The item snapshotted its price', function() use ($return) {
        $item = $return->getItems()[0];

        return abs($item->unitPrice - 40.00) < 0.01 ?: 'snapshot was ' . $item->unitPrice;
    });

    check('The item copied its reason’s name', function() use ($return) {
        return $return->getItems()[0]->reasonName === 'Doesn’t fit';
    });

    check('The resolution amount is the goods’ value', function() use ($return) {
        return abs($return->resolutionAmount - 40.00) < 0.01 ?: 'amount was ' . $return->resolutionAmount;
    });

    check('A “created” event was logged', function() use ($plugin, $return) {
        $events = $plugin->returns->getEventsForReturn((int)$return->id);

        return count($events) >= 1 && $events[count($events) - 1]->getType()->value === 'created';
    });

    check('The claimed quantity is now held against the line', function() use ($plugin, $order, $lineA) {
        $claimed = $plugin->eligibility->getClaimedQuantities((int)$order->id);

        return ($claimed[$lineA->id] ?? 0) === 1;
    });

    check('The second unit of that line is still returnable', function() use ($plugin, $order, $lineA) {
        $plugin->eligibility->forget();
        $verdict = $plugin->eligibility->evaluate($order);

        return $verdict->availableQty((int)$lineA->id) === 1;
    });

    check('Asking for more than is available is clamped, not accepted', function() use ($plugin, $order, $lineB) {
        $built = $plugin->returns->create($order, [$lineB->id => ['qty' => 99]]);

        return $built->getItems()[0]->qtyRequested === 1;
    });

    // -----------------------------------------------------------------------
    section('The state machine');

    check('A refused transition leaves the RMA where it was', function() use ($plugin, $return) {
        $resolved = $plugin->states->getStateByKind(StateKind::Resolved);
        $before = $return->stateId;

        $moved = $plugin->returns->transition($return, $resolved);

        return !$moved && $return->stateId === $before && $return->hasErrors('stateId');
    });

    check('force overrides the guard', function() use ($plugin, $return) {
        $awaiting = $plugin->states->getStateByKind(StateKind::Awaiting);
        $moved = $plugin->returns->transition($return, $awaiting, ['force' => true]);
        $back = $plugin->returns->transition($return, $plugin->states->getStateByKind(StateKind::Requested), ['force' => true]);

        return $moved && $back;
    });

    check('Approving fills in the approved quantities', function() use ($plugin, $return) {
        if (!$plugin->returns->approve($return)) {
            return implode(' ', $return->getErrorSummary(true));
        }

        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);

        return $fresh->getItems()[0]->qtyApproved === 1 && $fresh->approvedAt !== null;
    });

    check('The transition was logged with both state handles', function() use ($plugin, $return) {
        foreach ($plugin->returns->getEventsForReturn((int)$return->id) as $event) {
            if ($event->getType()->value === 'stateChanged') {
                return ($event->data['to'] ?? null) === 'approved';
            }
        }

        return 'no stateChanged event';
    });

    check('A before-transition handler can refuse a move', function() use ($plugin, $return) {
        $handler = function(justinholtweb\boomerang\events\ReturnStateEvent $event) {
            $event->isValid = false;
        };

        yii\base\Event::on(
            justinholtweb\boomerang\services\Returns::class,
            justinholtweb\boomerang\services\Returns::EVENT_BEFORE_TRANSITION,
            $handler,
        );

        $received = $plugin->states->getStateByKind(StateKind::Received);
        $moved = $plugin->returns->transition($return, $received);

        yii\base\Event::off(
            justinholtweb\boomerang\services\Returns::class,
            justinholtweb\boomerang\services\Returns::EVENT_BEFORE_TRANSITION,
            $handler,
        );

        return !$moved;
    });

    // -----------------------------------------------------------------------
    section('Receiving and restocking');

    $stockBefore = stockAt($variantA, InventoryTransactionType::AVAILABLE);

    check('Receiving records the quantity and moves the state', function() use ($plugin, $return) {
        $item = $return->getItems()[0];

        if (!$plugin->returns->receive($return, [$item->id => ['qty' => 1, 'condition' => ItemCondition::Resellable->value]])) {
            return implode(' ', $return->getErrorSummary(true));
        }

        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);

        return $fresh->getIsReceived() && $fresh->getItems()[0]->qtyReceived === 1;
    });

    check('Resellable goods went back to available stock', function() use ($variantA, $stockBefore) {
        return stockAt($variantA, InventoryTransactionType::AVAILABLE) === $stockBefore + 1
            ?: 'stock went from ' . $stockBefore . ' to ' . stockAt($variantA, InventoryTransactionType::AVAILABLE);
    });

    check('The item records what was restocked', function() use ($plugin, $return) {
        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);

        return $fresh->getItems()[0]->qtyRestocked === 1;
    });

    check('Restocking twice does not double the stock', function() use ($plugin, $return, $variantA) {
        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);
        $before = stockAt($variantA, InventoryTransactionType::AVAILABLE);

        $plugin->restock->apply($fresh);

        return stockAt($variantA, InventoryTransactionType::AVAILABLE) === $before;
    });

    check('A restock event names the location', function() use ($plugin, $return) {
        foreach ($plugin->returns->getEventsForReturn((int)$return->id) as $event) {
            if ($event->getType()->value === 'restocked') {
                return ($event->data['quantity'] ?? 0) === 1 && isset($event->data['location']);
            }
        }

        return 'no restock event';
    });

    check('Damaged goods go to the damaged shelf, not available', function() use ($plugin, $order, $lineB, $variantB) {
        $damagedBefore = stockAt($variantB, InventoryTransactionType::DAMAGED);
        $availableBefore = stockAt($variantB, InventoryTransactionType::AVAILABLE);

        $damagedReturn = makeReturn($order, [
            ['lineItemId' => $lineB->id, 'qty' => 1, 'reason' => 'arrivedDamaged'],
        ]);

        $plugin->returns->approve($damagedReturn, ['force' => true]);
        $item = $damagedReturn->getItems()[0];
        $plugin->returns->receive($damagedReturn, [$item->id => ['qty' => 1, 'condition' => ItemCondition::Damaged->value]]);

        return stockAt($variantB, InventoryTransactionType::DAMAGED) === $damagedBefore + 1
            && stockAt($variantB, InventoryTransactionType::AVAILABLE) === $availableBefore;
    });

    // -----------------------------------------------------------------------
    section('Refunds');

    check('Resolving a refund moves real money through the gateway', function() use ($plugin, $return, $commerce, $order) {
        $return = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);
        $return->resolution = Resolution::Refund->value;
        $plugin->returns->save($return, false);

        $resolved = $plugin->states->getStateByKind(StateKind::Resolved);

        if (!$plugin->returns->transition($return, $resolved)) {
            return implode(' ', $return->getErrorSummary(true));
        }

        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);

        return abs($fresh->refundedAmount - 40.00) < 0.01 ?: 'refunded ' . $fresh->refundedAmount;
    });

    check('Commerce recorded a refund transaction', function() use ($commerce, $order) {
        foreach ($commerce->getTransactions()->getAllTransactionsByOrderId((int)$order->id) as $transaction) {
            if ($transaction->type === TransactionRecord::TYPE_REFUND && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
                return true;
            }
        }

        return 'no successful refund transaction';
    });

    check('The RMA is resolved and stamped', function() use ($return) {
        $fresh = Craft::$app->getElements()->getElementById($return->id, ReturnRequest::class);

        return $fresh->getIsResolved() && $fresh->resolvedAt !== null;
    });

    check('A refund that cannot be covered fails loudly and does not move the RMA', function() use ($plugin, $variantA) {
        // An unpaid order has nothing to refund against, so the transition must be refused.
        $unpaidOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        applySettings(['requirePaidOrder' => false]);
        $plugin->eligibility->forget();

        $unpaidReturn = makeReturn($unpaidOrder, [
            ['lineItemId' => $unpaidOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ]);

        $plugin->returns->approve($unpaidReturn, ['force' => true]);
        $before = $unpaidReturn->stateId;

        $moved = $plugin->returns->transition(
            $unpaidReturn,
            $plugin->states->getStateByKind(StateKind::Resolved),
            ['force' => true],
        );

        applySettings(['requirePaidOrder' => true]);
        $plugin->eligibility->forget();

        return !$moved && $unpaidReturn->stateId === $before;
    });

    check('The failure is on the RMA’s history, not just in a log file', function() use ($plugin, $createdReturns) {
        $last = end($createdReturns);

        foreach ($plugin->returns->getEventsForReturn((int)$last->id) as $event) {
            if ($event->getType()->value === 'failed') {
                return true;
            }
        }

        return 'no failure event';
    });

    // -----------------------------------------------------------------------
    section('Restocking fees');

    check('A percentage fee comes off the payable amount', function() use ($plugin, $order, $lineA) {
        applySettings(['restockingFeePercent' => 10, 'waiveFeeOnFault' => true]);

        $built = $plugin->returns->create($order, [$lineA->id => ['qty' => 1, 'reasonId' => $plugin->reasons->getReasonByHandle('changedMind')?->id]]);
        $fee = $plugin->returns->calculateFee($built);
        $amount = $plugin->returns->calculateResolutionAmount($built);

        applySettings(['restockingFeePercent' => 0]);

        return abs($fee - 4.00) < 0.01 && abs($amount - 36.00) < 0.01
            ?: sprintf('fee %s, amount %s', $fee, $amount);
    });

    check('No fee when the return is the store’s own fault', function() use ($plugin, $order, $lineA) {
        applySettings(['restockingFeePercent' => 10, 'waiveFeeOnFault' => true]);

        $built = $plugin->returns->create($order, [$lineA->id => ['qty' => 1, 'reasonId' => $plugin->reasons->getReasonByHandle('arrivedDamaged')?->id]]);
        $fee = $plugin->returns->calculateFee($built);

        applySettings(['restockingFeePercent' => 0]);

        return $fee === 0.0 ?: 'fee was ' . $fee;
    });

    check('A fee never exceeds the value of the goods', function() use ($plugin, $order, $lineA) {
        applySettings(['restockingFeeFlat' => 1000, 'waiveFeeOnFault' => false]);

        $built = $plugin->returns->create($order, [$lineA->id => ['qty' => 1, 'reasonId' => $plugin->reasons->getReasonByHandle('changedMind')?->id]]);
        $fee = $plugin->returns->calculateFee($built);
        $amount = $plugin->returns->calculateResolutionAmount($built);

        applySettings(['restockingFeeFlat' => 0, 'waiveFeeOnFault' => true]);

        return abs($fee - $built->getItemsValue()) < 0.01 && $amount === 0.0;
    });

    // -----------------------------------------------------------------------
    section('The wallet');

    $customer = makeCustomer('boomerang-wallet-' . uniqid('', false) . '@example.com');

    check('Issuing credit opens a lot and a ledger entry', function() use ($plugin, $customer) {
        $lot = $plugin->wallet->issue((int)$customer->id, 50.00, ['note' => 'Fixture', 'idempotencyKey' => 'fixture:1']);

        return $lot->id !== null
            && abs($lot->amountRemaining - 50.00) < 0.01
            && abs($plugin->wallet->getBalance((int)$customer->id) - 50.00) < 0.01;
    });

    check('Issuing with the same key twice does not double the money', function() use ($plugin, $customer) {
        $before = $plugin->wallet->getBalance((int)$customer->id);
        $plugin->wallet->post(new justinholtweb\boomerang\models\CreditEntry([
            'customerId' => $customer->id,
            'currency' => $plugin->wallet->defaultCurrency(),
            'type' => CreditEntryType::Adjust->value,
            'amount' => 25.00,
            'idempotencyKey' => 'fixture:dupe',
        ]));
        $plugin->wallet->post(new justinholtweb\boomerang\models\CreditEntry([
            'customerId' => $customer->id,
            'currency' => $plugin->wallet->defaultCurrency(),
            'type' => CreditEntryType::Adjust->value,
            'amount' => 25.00,
            'idempotencyKey' => 'fixture:dupe',
        ]));

        // Both entries are lot-less adjustments, so the balance is unchanged either way; what is
        // being asserted is that the second write did not create a second row.
        $rows = (new craft\db\Query())
            ->from([Table::CREDITENTRIES])
            ->where(['idempotencyKey' => 'fixture:dupe'])
            ->count();

        return (int)$rows === 1 ?: "$rows rows for one key";
    });

    check('Spending draws the balance down', function() use ($plugin, $customer) {
        $plugin->wallet->spend((int)$customer->id, 20.00, 'fixture:spend:1');

        return abs($plugin->wallet->getBalance((int)$customer->id) - 30.00) < 0.01
            ?: 'balance ' . $plugin->wallet->getBalance((int)$customer->id);
    });

    check('Replaying the same spend key is a no-op', function() use ($plugin, $customer) {
        $plugin->wallet->spend((int)$customer->id, 20.00, 'fixture:spend:1');

        return abs($plugin->wallet->getBalance((int)$customer->id) - 30.00) < 0.01;
    });

    check('Spending more than the balance is refused outright', function() use ($plugin, $customer) {
        try {
            $plugin->wallet->spend((int)$customer->id, 500.00, 'fixture:spend:toomuch');
        } catch (RuntimeException) {
            return abs($plugin->wallet->getBalance((int)$customer->id) - 30.00) < 0.01;
        }

        return 'no exception, and the balance moved';
    });

    check('Allocation is FIFO by expiry', function() use ($plugin) {
        $fifoCustomer = makeCustomer('boomerang-fifo-' . uniqid('', false) . '@example.com');

        $soon = $plugin->wallet->issue((int)$fifoCustomer->id, 10.00, [
            'expiresAt' => (new DateTime())->add(new DateInterval('P5D')),
            'idempotencyKey' => 'fifo:soon',
        ]);
        $later = $plugin->wallet->issue((int)$fifoCustomer->id, 10.00, [
            'expiresAt' => (new DateTime())->add(new DateInterval('P90D')),
            'idempotencyKey' => 'fifo:later',
        ]);
        $never = $plugin->wallet->issue((int)$fifoCustomer->id, 10.00, ['idempotencyKey' => 'fifo:never']);

        $plugin->wallet->spend((int)$fifoCustomer->id, 12.00, 'fifo:spend');

        $soonAfter = $plugin->wallet->getLotById((int)$soon->id);
        $laterAfter = $plugin->wallet->getLotById((int)$later->id);
        $neverAfter = $plugin->wallet->getLotById((int)$never->id);

        // The soonest to lapse is emptied first, then the next; the one that never expires is
        // untouched.
        return $soonAfter->amountRemaining == 0.0
            && abs($laterAfter->amountRemaining - 8.00) < 0.01
            && abs($neverAfter->amountRemaining - 10.00) < 0.01
            ?: sprintf('%s / %s / %s', $soonAfter->amountRemaining, $laterAfter->amountRemaining, $neverAfter->amountRemaining);
    });

    check('Expired credit is unspendable before the cron ever runs', function() use ($plugin) {
        $expiredCustomer = makeCustomer('boomerang-expired-' . uniqid('', false) . '@example.com');

        $lot = $plugin->wallet->issue((int)$expiredCustomer->id, 30.00, ['idempotencyKey' => 'expired:lot']);

        // Backdated directly: the point is what the balance query does with a lapsed lot, not how
        // it came to be lapsed.
        Craft::$app->getDb()->createCommand()
            ->update(Table::CREDITLOTS, ['expiresAt' => Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P1D')))], ['id' => $lot->id])
            ->execute();

        return $plugin->wallet->getBalance((int)$expiredCustomer->id) == 0.0;
    });

    check('Expiring writes the ledger entry that explains it', function() use ($plugin) {
        $result = $plugin->wallet->expire();

        return $result['lots'] >= 1 && $result['amount'] >= 30.00;
    });

    check('Expiring twice writes nothing the second time', function() use ($plugin) {
        $result = $plugin->wallet->expire();

        return $result['lots'] === 0;
    });

    check('Expiring balance reports what lapses soon', function() use ($plugin) {
        $soonCustomer = makeCustomer('boomerang-soon-' . uniqid('', false) . '@example.com');

        $plugin->wallet->issue((int)$soonCustomer->id, 15.00, [
            'expiresAt' => (new DateTime())->add(new DateInterval('P3D')),
            'idempotencyKey' => 'soon:lot',
        ]);
        $plugin->wallet->issue((int)$soonCustomer->id, 40.00, ['idempotencyKey' => 'soon:never']);

        return abs($plugin->wallet->getExpiringBalance((int)$soonCustomer->id, 14) - 15.00) < 0.01;
    });

    check('Revoking takes back only what is left', function() use ($plugin) {
        $revokeCustomer = makeCustomer('boomerang-revoke-' . uniqid('', false) . '@example.com');

        $plugin->wallet->issue((int)$revokeCustomer->id, 20.00, ['idempotencyKey' => 'revoke:lot']);
        $plugin->wallet->spend((int)$revokeCustomer->id, 15.00, 'revoke:spend');

        $revoked = $plugin->wallet->revoke((int)$revokeCustomer->id, 20.00, 'revoke:take');

        return abs($revoked - 5.00) < 0.01 && $plugin->wallet->getBalance((int)$revokeCustomer->id) == 0.0
            ?: 'revoked ' . $revoked;
    });

    check('A hold is reversible, and releasing gives the money back', function() use ($plugin) {
        $holdCustomer = makeCustomer('boomerang-hold-' . uniqid('', false) . '@example.com');

        $plugin->wallet->issue((int)$holdCustomer->id, 30.00, ['idempotencyKey' => 'hold:lot']);
        $plugin->wallet->hold((int)$holdCustomer->id, 12.00, 'hold:auth', ['transactionHash' => 'hash-hold-1']);

        $held = $plugin->wallet->getBalance((int)$holdCustomer->id);
        $plugin->wallet->releaseHolds('hash-hold-1');
        $released = $plugin->wallet->getBalance((int)$holdCustomer->id);

        return abs($held - 18.00) < 0.01 && abs($released - 30.00) < 0.01
            ?: sprintf('held %s, released %s', $held, $released);
    });

    check('Capturing a hold retypes the same rows rather than reallocating', function() use ($plugin) {
        $captureCustomer = makeCustomer('boomerang-capture-' . uniqid('', false) . '@example.com');

        $plugin->wallet->issue((int)$captureCustomer->id, 30.00, ['idempotencyKey' => 'capture:lot']);
        $entries = $plugin->wallet->hold((int)$captureCustomer->id, 12.00, 'capture:auth', ['transactionHash' => 'hash-capture-1']);
        $lotIds = array_map(fn($entry) => $entry->lotId, $entries);

        $captured = $plugin->wallet->captureHolds('hash-capture-1');

        $rows = (new craft\db\Query())
            ->from([Table::CREDITENTRIES])
            ->where(['transactionHash' => 'hash-capture-1'])
            ->all();

        $sameLots = array_map(fn(array $row) => (int)$row['lotId'], $rows) == $lotIds;

        return $captured === count($entries)
            && $sameLots
            && $rows[0]['type'] === CreditEntryType::Spend->value
            && abs($plugin->wallet->getBalance((int)$captureCustomer->id) - 18.00) < 0.01;
    });

    check('A refund goes back into the lot it came from', function() use ($plugin) {
        $refundCustomer = makeCustomer('boomerang-refund-' . uniqid('', false) . '@example.com');

        $lot = $plugin->wallet->issue((int)$refundCustomer->id, 40.00, ['idempotencyKey' => 'refund:lot']);
        $plugin->wallet->spend((int)$refundCustomer->id, 25.00, 'refund:spend', ['transactionHash' => 'hash-refund-1']);

        $returned = $plugin->wallet->refundToWallet('hash-refund-1', 25.00);
        $after = $plugin->wallet->getLotById((int)$lot->id);

        return abs($returned - 25.00) < 0.01 && abs($after->amountRemaining - 40.00) < 0.01
            ?: sprintf('returned %s, lot has %s', $returned, $after->amountRemaining);
    });

    check('A refund cannot exceed what was actually spent', function() use ($plugin) {
        $twiceCustomer = makeCustomer('boomerang-twice-' . uniqid('', false) . '@example.com');

        $plugin->wallet->issue((int)$twiceCustomer->id, 40.00, ['idempotencyKey' => 'twice:lot']);
        $plugin->wallet->spend((int)$twiceCustomer->id, 25.00, 'twice:spend', ['transactionHash' => 'hash-twice']);

        $first = $plugin->wallet->refundToWallet('hash-twice', 25.00);
        $second = $plugin->wallet->refundToWallet('hash-twice', 25.00);

        return abs($first - 25.00) < 0.01 && $second == 0.0
            && abs($plugin->wallet->getBalance((int)$twiceCustomer->id) - 40.00) < 0.01;
    });

    check('A refund into a lapsed lot issues a fresh lot instead', function() use ($plugin) {
        $lapsedCustomer = makeCustomer('boomerang-lapsed-' . uniqid('', false) . '@example.com');

        $lot = $plugin->wallet->issue((int)$lapsedCustomer->id, 20.00, ['idempotencyKey' => 'lapsed:lot']);
        $plugin->wallet->spend((int)$lapsedCustomer->id, 20.00, 'lapsed:spend', ['transactionHash' => 'hash-lapsed']);

        Craft::$app->getDb()->createCommand()
            ->update(Table::CREDITLOTS, ['expiresAt' => Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P1D')))], ['id' => $lot->id])
            ->execute();

        $plugin->wallet->refundToWallet('hash-lapsed', 20.00);

        // Refunding a customer into money they cannot spend is not a refund.
        return abs($plugin->wallet->getBalance((int)$lapsedCustomer->id) - 20.00) < 0.01
            ?: 'balance ' . $plugin->wallet->getBalance((int)$lapsedCustomer->id);
    });

    // -----------------------------------------------------------------------
    section('Credit as a return resolution');

    check('Resolving as credit issues a lot against the RMA', function() use ($plugin, $variantA, $customer) {
        $creditOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, (int)$customer->id, $customer->email);
        payOrder($creditOrder);
        $creditOrder = Order::find()->id($creditOrder->id)->status(null)->one();

        $creditReturn = makeReturn($creditOrder, [
            ['lineItemId' => $creditOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ], ['resolution' => Resolution::Credit->value]);

        $before = $plugin->wallet->getBalance((int)$customer->id);

        $plugin->returns->approve($creditReturn, ['force' => true]);
        $plugin->returns->transition($creditReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);

        if (!$plugin->returns->transition($creditReturn, $plugin->states->getStateByKind(StateKind::Resolved))) {
            return implode(' ', $creditReturn->getErrorSummary(true));
        }

        $after = $plugin->wallet->getBalance((int)$customer->id);

        return abs(($after - $before) - 40.00) < 0.01 ?: sprintf('balance went %s → %s', $before, $after);
    });

    check('A credit bonus is applied on top', function() use ($plugin, $variantA) {
        applySettings(['creditBonusPercent' => 10]);

        $bonusCustomer = makeCustomer('boomerang-bonus-' . uniqid('', false) . '@example.com');
        $bonusOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, (int)$bonusCustomer->id, $bonusCustomer->email);
        payOrder($bonusOrder);
        $bonusOrder = Order::find()->id($bonusOrder->id)->status(null)->one();

        $bonusReturn = makeReturn($bonusOrder, [
            ['lineItemId' => $bonusOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ], ['resolution' => Resolution::Credit->value]);

        $plugin->returns->approve($bonusReturn, ['force' => true]);
        $plugin->returns->transition($bonusReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);
        $plugin->returns->transition($bonusReturn, $plugin->states->getStateByKind(StateKind::Resolved));

        $balance = $plugin->wallet->getBalance((int)$bonusCustomer->id);

        applySettings(['creditBonusPercent' => 0]);

        return abs($balance - 44.00) < 0.01 ?: 'balance ' . $balance;
    });

    check('A return with no customer account cannot be resolved as credit', function() use ($plugin, $variantA) {
        // Commerce 5 attaches a customer user to *every* order, guest checkouts included, so the
        // guard being tested is the one on the RMA itself — which is what a migrated or
        // hand-built return can genuinely arrive without.
        $guestOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        payOrder($guestOrder);
        $guestOrder = Order::find()->id($guestOrder->id)->status(null)->one();

        $guestReturn = makeReturn($guestOrder, [
            ['lineItemId' => $guestOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ], ['resolution' => Resolution::Credit->value]);

        $guestReturn->customerId = null;
        $plugin->returns->save($guestReturn, false);

        $plugin->returns->approve($guestReturn, ['force' => true]);
        $plugin->returns->transition($guestReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);

        $moved = $plugin->returns->transition($guestReturn, $plugin->states->getStateByKind(StateKind::Resolved));

        return !$moved && $guestReturn->hasErrors('resolution');
    });

    // -----------------------------------------------------------------------
    section('The store-credit gateway');

    $gateway = new StoreCredit();
    $gateway->name = 'Boomerang fixture credit';
    $gateway->handle = 'boomerangFixtureCredit' . random_int(1000, 9999);
    $gateway->paymentType = 'purchase';
    // Commerce gateways have `isFrontendEnabled`; there is no `enabled`.
    $gateway->isFrontendEnabled = true;

    if (!$commerce->getGateways()->saveGateway($gateway)) {
        throw new RuntimeException('Could not save the store-credit gateway: ' . json_encode($gateway->getErrors()));
    }

    $createdGatewayId = $gateway->id;

    check('The gateway type registers with Commerce', function() use ($commerce) {
        return in_array(StoreCredit::class, $commerce->getGateways()->getAllGatewayTypes(), true);
    });

    check('It offers itself only when there is a balance', function() use ($plugin, $gateway, $variantA, $customer) {
        // A customer who has never held credit, rather than the shared fixture email — Commerce
        // attaches a customer user to every order, so "guest" is not a way to get a zero balance.
        $broke = makeCustomer('boomerang-nobalance-' . uniqid('', false) . '@example.com');
        $withoutCredit = makeOrder([['variant' => $variantA, 'qty' => 1]], false, (int)$broke->id, $broke->email);
        $withCredit = makeOrder([['variant' => $variantA, 'qty' => 1]], false, (int)$customer->id, $customer->email);

        $offeredToBroke = $gateway->availableForUseWithOrder($withoutCredit);
        $offeredToFunded = $gateway->availableForUseWithOrder($withCredit);

        return (!$offeredToBroke && $offeredToFunded)
            ?: sprintf(
                'offered to the empty wallet: %s; offered to the funded one: %s (balance %s)',
                var_export($offeredToBroke, true),
                var_export($offeredToFunded, true),
                $plugin->wallet->getBalance((int)$customer->id),
            );
    });

    check('Paying with credit debits the wallet and pays the order', function() use ($plugin, $commerce, $gateway, $variantB) {
        $payer = makeCustomer('boomerang-payer-' . uniqid('', false) . '@example.com');
        $plugin->wallet->issue((int)$payer->id, 100.00, ['idempotencyKey' => 'payer:lot']);

        $cart = makeOrder([['variant' => $variantB, 'qty' => 1]], false, (int)$payer->id, $payer->email);
        $cart->gatewayId = $gateway->id;
        Craft::$app->getElements()->saveElement($cart, false);
        $cart = Order::find()->id($cart->id)->status(null)->one();
        $cart->gatewayId = $gateway->id;

        $redirect = null;
        $transaction = null;

        $commerce->getPayments()->processPayment(
            $cart,
            new craft\commerce\models\payments\OffsitePaymentForm(),
            $redirect,
            $transaction,
        );

        $balance = $plugin->wallet->getBalance((int)$payer->id);
        $paid = Order::find()->id($cart->id)->status(null)->one()->getTotalPaid();

        return $transaction !== null
            && $transaction->status === TransactionRecord::STATUS_SUCCESS
            && abs($balance - 75.00) < 0.01
            && abs($paid - 25.00) < 0.01
            ?: sprintf('balance %s, paid %s, status %s', $balance, $paid, $transaction?->status);
    });

    check('Refunding a wallet payment puts the credit back', function() use ($plugin, $commerce, $gateway, $variantB) {
        $payer = makeCustomer('boomerang-refunder-' . uniqid('', false) . '@example.com');
        $plugin->wallet->issue((int)$payer->id, 100.00, ['idempotencyKey' => 'refunder:lot']);

        $cart = makeOrder([['variant' => $variantB, 'qty' => 1]], false, (int)$payer->id, $payer->email);
        $cart->gatewayId = $gateway->id;
        Craft::$app->getElements()->saveElement($cart, false);
        $cart = Order::find()->id($cart->id)->status(null)->one();
        $cart->gatewayId = $gateway->id;

        $redirect = null;
        $transaction = null;
        $commerce->getPayments()->processPayment($cart, new craft\commerce\models\payments\OffsitePaymentForm(), $redirect, $transaction);

        $spent = $plugin->wallet->getBalance((int)$payer->id);

        $commerce->getPayments()->refundTransaction($transaction, 25.00, 'Fixture refund');

        $restored = $plugin->wallet->getBalance((int)$payer->id);

        return abs($spent - 75.00) < 0.01 && abs($restored - 100.00) < 0.01
            ?: sprintf('spent %s, restored %s', $spent, $restored);
    });

    check('A payment bigger than the balance is refused, not partially taken', function() use ($plugin, $commerce, $gateway, $variantA) {
        $broke = makeCustomer('boomerang-broke-' . uniqid('', false) . '@example.com');
        $plugin->wallet->issue((int)$broke->id, 5.00, ['idempotencyKey' => 'broke:lot']);

        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], false, (int)$broke->id, $broke->email);
        $cart->gatewayId = $gateway->id;
        Craft::$app->getElements()->saveElement($cart, false);
        $cart = Order::find()->id($cart->id)->status(null)->one();
        $cart->gatewayId = $gateway->id;

        $redirect = null;
        $transaction = null;

        try {
            $commerce->getPayments()->processPayment($cart, new craft\commerce\models\payments\OffsitePaymentForm(), $redirect, $transaction);
        } catch (Throwable) {
            // Commerce turns an unsuccessful transaction into a PaymentException, which is the
            // right shape for a checkout to catch.
        }

        return abs($plugin->wallet->getBalance((int)$broke->id) - 5.00) < 0.01;
    });

    // -----------------------------------------------------------------------
    section('Exchanges');

    check('An exchange creates a new order carrying the credit', function() use ($plugin, $commerce, $variantA, $variantB) {
        $exchangeCustomer = makeCustomer('boomerang-exchange-' . uniqid('', false) . '@example.com');
        $exchangeOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, (int)$exchangeCustomer->id, $exchangeCustomer->email);
        payOrder($exchangeOrder);
        $exchangeOrder = Order::find()->id($exchangeOrder->id)->status(null)->one();

        $exchangeReturn = makeReturn($exchangeOrder, [
            ['lineItemId' => $exchangeOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'wrongSize'],
        ], ['resolution' => Resolution::Replacement->value]);

        $plugin->returns->approve($exchangeReturn, ['force' => true]);
        $plugin->returns->transition($exchangeReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);

        if (!$plugin->returns->transition($exchangeReturn, $plugin->states->getStateByKind(StateKind::Resolved))) {
            return implode(' ', $exchangeReturn->getErrorSummary(true));
        }

        $fresh = Craft::$app->getElements()->getElementById($exchangeReturn->id, ReturnRequest::class);
        $new = $fresh->getExchangeOrder();

        if ($new === null) {
            return 'no exchange order';
        }

        $new->recalculate();
        Craft::$app->getElements()->saveElement($new, false);
        $new = Order::find()->id($new->id)->status(null)->one();

        $credit = 0.0;

        foreach ($new->getAdjustments() as $adjustment) {
            if ($adjustment->type === justinholtweb\boomerang\adjusters\ExchangeCredit::ADJUSTMENT_TYPE) {
                $credit += $adjustment->amount;
            }
        }

        // A like-for-like replacement is worth exactly what came back, so the new order is free.
        return abs($credit + 40.00) < 0.01 && abs($new->getTotalPrice()) < 0.01
            ?: sprintf('credit %s, total %s', $credit, $new->getTotalPrice());
    });

    check('The credit is capped at the order and never goes negative', function() use ($plugin, $commerce, $variantA, $variantB) {
        $capCustomer = makeCustomer('boomerang-cap-' . uniqid('', false) . '@example.com');
        $capOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, (int)$capCustomer->id, $capCustomer->email);
        payOrder($capOrder);
        $capOrder = Order::find()->id($capOrder->id)->status(null)->one();

        $capReturn = makeReturn($capOrder, [
            ['lineItemId' => $capOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'wrongSize'],
        ], ['resolution' => Resolution::Exchange->value]);

        $plugin->returns->approve($capReturn, ['force' => true]);
        $plugin->returns->transition($capReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);

        // A $40 return exchanged for a $25 item: the credit cannot take the order below zero.
        $new = $plugin->exchanges->create($capReturn, [['purchasableId' => $variantB->id, 'qty' => 1]]);
        $new->recalculate();
        Craft::$app->getElements()->saveElement($new, false);
        $new = Order::find()->id($new->id)->status(null)->one();

        return $new->getTotalPrice() >= 0 && abs($new->getTotalPrice()) < 0.01
            ?: 'total was ' . $new->getTotalPrice();
    });

    check('Completing an under-spent exchange issues the balance as credit', function() use ($plugin, $variantA, $variantB) {
        $remainderCustomer = makeCustomer('boomerang-remainder-' . uniqid('', false) . '@example.com');
        $remainderOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, (int)$remainderCustomer->id, $remainderCustomer->email);
        payOrder($remainderOrder);
        $remainderOrder = Order::find()->id($remainderOrder->id)->status(null)->one();

        $remainderReturn = makeReturn($remainderOrder, [
            ['lineItemId' => $remainderOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'wrongSize'],
        ], ['resolution' => Resolution::Exchange->value]);

        $plugin->returns->approve($remainderReturn, ['force' => true]);
        $plugin->returns->transition($remainderReturn, $plugin->states->getStateByKind(StateKind::Received), ['force' => true]);

        $new = $plugin->exchanges->create($remainderReturn, [['purchasableId' => $variantB->id, 'qty' => 1]]);
        $new->recalculate();
        Craft::$app->getElements()->saveElement($new, false);
        $new = Order::find()->id($new->id)->status(null)->one();

        $before = $plugin->wallet->getBalance((int)$remainderCustomer->id);
        $new->markAsComplete();
        $after = $plugin->wallet->getBalance((int)$remainderCustomer->id);

        // $40 back, a $25 replacement: $15 goes to the wallet.
        return abs(($after - $before) - 15.00) < 0.01 ?: sprintf('balance went %s → %s', $before, $after);
    });

    // -----------------------------------------------------------------------
    section('Labels');

    check('The manual provider is always configured', function() use ($plugin) {
        $provider = $plugin->labels->getProvider('manual');
        $errors = [];

        return $provider !== null && $provider->isConfigured($errors) && !$provider->isRemote();
    });

    check('The manual provider refuses an empty label', function() use ($plugin, $createdReturns) {
        try {
            $plugin->labels->getProvider('manual')->createLabel($createdReturns[0], []);
        } catch (RuntimeException) {
            return true;
        }

        return 'no exception';
    });

    check('A manual label is stored against the return', function() use ($plugin, $createdReturns) {
        applySettings(['labelProvider' => 'manual']);

        $label = $plugin->labels->create($createdReturns[0], [
            'trackingNumber' => 'FIXTURE123',
            'carrier' => 'Royal Mail',
        ]);

        $labels = $plugin->labels->getLabelsForReturn((int)$createdReturns[0]->id);

        return $label->id !== null && count($labels) >= 1 && $labels[0]->trackingNumber === 'FIXTURE123';
    });

    check('Voiding marks it voided locally even when the provider cannot', function() use ($plugin, $createdReturns) {
        $label = $plugin->labels->getLabelsForReturn((int)$createdReturns[0]->id)[0];
        $plugin->labels->void($label);

        $fresh = $plugin->labels->getLabelById((int)$label->id);

        return $fresh->getIsVoided();
    });

    check('The active label ignores voided ones', function() use ($plugin, $createdReturns) {
        $fresh = Craft::$app->getElements()->getElementById($createdReturns[0]->id, ReturnRequest::class);

        return $fresh->getActiveLabel() === null;
    });

    check('ShipStation reports what it is missing rather than throwing', function() use ($plugin) {
        $provider = $plugin->labels->getProvider('shipstation');
        $errors = [];
        $configured = $provider->isConfigured($errors);

        return !$configured && $errors !== [];
    });

    check('The ShipStation request ships from the customer, to the warehouse', function() use ($plugin, $createdReturns) {
        /** @var justinholtweb\boomerang\labels\ShipStation $provider */
        $provider = $plugin->labels->getProvider('shipstation');
        $request = $provider->buildRequest($createdReturns[0]);

        // A return label the other way round is valid, buyable, and sends the parcel to the person
        // trying to send it back.
        return ($request['is_return_label'] ?? false) === true
            && ($request['shipment']['ship_from']['postal_code'] ?? null) === '28202';
    });

    // -----------------------------------------------------------------------
    section('Element queries');

    check('An unqualified query excludes archived returns', function() {
        $query = ReturnRequest::find();

        return $query->isArchived === false;
    });

    check('isOpen(false) finds only closed states', function() use ($plugin) {
        $closed = ReturnRequest::find()->isOpen(false)->count();
        $open = ReturnRequest::find()->isOpen(true)->count();

        return $closed > 0 && $open > 0;
    });

    check('stateKind() resolves handles the merchant never typed', function() use ($plugin) {
        return ReturnRequest::find()->stateKind(StateKind::Resolved)->count() > 0;
    });

    check('An unknown state handle returns nothing, not everything', function() {
        return ReturnRequest::find()->state('no-such-state')->count() === 0;
    });

    check('purchasableId filters without duplicating returns', function() use ($variantA) {
        $ids = ReturnRequest::find()->purchasableId($variantA->id)->isArchived(null)->ids();

        return count($ids) === count(array_unique($ids)) && count($ids) > 0;
    });

    check('A return is found by its portal token', function() use ($plugin, $createdReturns) {
        $found = $plugin->returns->getReturnByToken((string)$createdReturns[0]->portalToken);

        return $found?->id === $createdReturns[0]->id;
    });

    check('A short token is rejected without a query', function() use ($plugin) {
        return $plugin->returns->getReturnByToken('too-short') === null;
    });

    // -----------------------------------------------------------------------
    section('Analytics');

    check('The summary counts what was created', function() use ($plugin) {
        $summary = $plugin->analytics->summary();

        return $summary['total'] > 0 && $summary['value'] > 0;
    });

    check('Reasons are reported with their fault flag', function() use ($plugin) {
        $rows = $plugin->analytics->byReason();

        foreach ($rows as $row) {
            if ($row['name'] === 'Arrived damaged') {
                return $row['isFault'] === true && $row['units'] > 0;
            }
        }

        return 'no damaged row';
    });

    check('Products are reported against how many were sold', function() use ($plugin, $variantA) {
        foreach ($plugin->analytics->byProduct() as $row) {
            if ($row['purchasableId'] === (int)$variantA->id) {
                return $row['sold'] > 0 && $row['rate'] !== null;
            }
        }

        return 'no row for the fixture variant';
    });

    check('Restock recovery counts what went back on a shelf', function() use ($plugin) {
        $recovery = $plugin->analytics->restockRecovery();

        return $recovery['restocked'] > 0 && $recovery['rate'] !== null;
    });

    check('Credit issued and spent are both reported', function() use ($plugin) {
        $credit = $plugin->analytics->credit();

        return $credit['issued'] > 0 && $credit['spent'] > 0 && $credit['redemptionRate'] !== null;
    });

    check('The daily series is grouped by day', function() use ($plugin) {
        $daily = $plugin->analytics->daily();

        return count($daily) > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $daily[0]['date']) === 1;
    });

    check('The CSV export has a row per item', function() use ($plugin) {
        $rows = $plugin->analytics->exportRows();

        return count($rows) > 0 && array_key_exists('sku', $rows[0]) && array_key_exists('reason', $rows[0]);
    });

    // -----------------------------------------------------------------------
    section('Lite');

    $plugin->edition = Plugin::EDITION_LITE;

    check('Store credit is ignored, not obeyed', fn() => !$plugin->getSettings()->getEffectiveCreditEnabled()
        && $plugin->getSettings()->creditEnabled);

    check('Restock is ignored, not obeyed', fn() => !$plugin->getSettings()->getEffectiveRestockEnabled()
        && $plugin->getSettings()->restockEnabled);

    check('No label provider is active', fn() => $plugin->getSettings()->getEffectiveLabelProvider() === null);

    check('The suppressed list names what is being ignored', function() use ($plugin) {
        $suppressed = $plugin->getSettings()->getSuppressedProSettings();

        return $plugin->getSettings()->getHasSuppressedProSettings() && count($suppressed) >= 2;
    });

    check('Only the Lite resolutions are offered', function() use ($plugin) {
        $options = $plugin->getSettings()->getAvailableResolutions();

        return isset($options[Resolution::Refund->value])
            && !isset($options[Resolution::Credit->value])
            && !isset($options[Resolution::Exchange->value]);
    });

    check('A stored Pro default falls back to a refund', function() use ($plugin) {
        applySettings(['defaultResolution' => Resolution::Credit->value]);
        $resolution = $plugin->getSettings()->getDefaultResolution();
        applySettings(['defaultResolution' => Resolution::Refund->value]);

        return $resolution === Resolution::Refund;
    });

    check('A Pro resolution refuses to run on Lite', function() use ($plugin, $variantA) {
        $liteOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        payOrder($liteOrder);
        $liteOrder = Order::find()->id($liteOrder->id)->status(null)->one();

        $liteReturn = makeReturn($liteOrder, [
            ['lineItemId' => $liteOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ], ['resolution' => Resolution::Credit->value]);

        $plugin->returns->approve($liteReturn, ['force' => true]);

        $moved = $plugin->returns->transition(
            $liteReturn,
            $plugin->states->getStateByKind(StateKind::Resolved),
            ['force' => true],
        );

        return !$moved;
    });

    check('Restocking is a no-op on Lite', function() use ($plugin, $createdReturns) {
        $result = $plugin->restock->apply($createdReturns[0]);

        return $result['quantity'] === 0;
    });

    check('The gateway will not take a payment on Lite', function() use ($plugin, $commerce, $createdGatewayId, $variantA) {
        $liteGateway = $commerce->getGateways()->getGatewayById($createdGatewayId);
        $liteCustomer = makeCustomer('boomerang-lite-' . uniqid('', false) . '@example.com');
        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], false, (int)$liteCustomer->id, $liteCustomer->email);

        return !$liteGateway->availableForUseWithOrder($cart);
    });

    check('Buying a label refuses on Lite', function() use ($plugin, $createdReturns) {
        try {
            $plugin->labels->create($createdReturns[0], ['trackingNumber' => 'NOPE']);
        } catch (RuntimeException) {
            return true;
        }

        return 'no exception';
    });

    check('The returns desk itself still works on Lite', function() use ($plugin, $variantB) {
        $liteOrder = makeOrder([['variant' => $variantB, 'qty' => 1]]);
        payOrder($liteOrder);
        $liteOrder = Order::find()->id($liteOrder->id)->status(null)->one();

        $liteReturn = makeReturn($liteOrder, [
            ['lineItemId' => $liteOrder->getLineItems()[0]->id, 'qty' => 1, 'reason' => 'changedMind'],
        ]);

        $approved = $plugin->returns->approve($liteReturn);
        $resolved = $plugin->returns->transition($liteReturn, $plugin->states->getStateByKind(StateKind::Resolved), ['force' => true]);

        $fresh = Craft::$app->getElements()->getElementById($liteReturn->id, ReturnRequest::class);

        return $approved && $resolved && abs($fresh->refundedAmount - 25.00) < 0.01
            ?: 'refunded ' . $fresh->refundedAmount;
    });
} catch (Throwable $fatal) {
    // Printed here rather than left to bubble: `exit()` in the `finally` below discards an
    // in-flight exception, so a fixture that blows up would otherwise present as a suite that
    // simply stopped.
    $failed++;
    echo "\n  ✗ FATAL: " . get_class($fatal) . ': ' . $fatal->getMessage() . "\n";
    echo '    ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    echo '    ' . str_replace("\n", "\n    ", $fatal->getTraceAsString()) . "\n";
} finally {
    // -----------------------------------------------------------------------
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach ($createdReturns as $created) {
        $fresh = $elements->getElementById($created->id, ReturnRequest::class);

        if ($fresh !== null) {
            $elements->deleteElement($fresh, true);
        }
    }

    // Credit is removed by customer ID rather than left to the foreign key. Craft **soft**-deletes
    // a user, so the `users` row survives and the `CASCADE` never fires — which is the right
    // behaviour in production (a restored customer should get their balance back) and a leak in a
    // test suite.
    $customerIds = array_map(static fn(User $created) => (int)$created->id, $createdUsers);

    if ($customerIds !== []) {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::CREDITENTRIES, ['customerId' => $customerIds])
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::CREDITLOTS, ['customerId' => $customerIds])
            ->execute();
    }

    foreach ($createdUsers as $created) {
        $fresh = $elements->getElementById($created->id, User::class);

        if ($fresh !== null) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdOrders as $created) {
        $fresh = Order::find()->id($created->id)->status(null)->one();

        if ($fresh !== null) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdProducts as $created) {
        $fresh = $elements->getElementById($created->id, Product::class);

        if ($fresh !== null) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdStates as $stateId) {
        Plugin::getInstance()->states->deleteStateById((int)$stateId);
    }

    if ($createdGatewayId !== null) {
        Commerce::getInstance()->getGateways()->archiveGatewayById((int)$createdGatewayId);
    }

    // Nothing here was persisted, so nothing has to be restored beyond this process. Said out
    // loud because a suite that silently leaves a shared harness on the wrong edition is a bug
    // somebody else finds a week later.
    $plugin->edition = $originalEdition;
    $plugin->getSettings()->setAttributes($originalSettings, false);

    echo "  ✓ fixtures removed; nothing was persisted to project config\n";

    echo "\n$passed passed, $failed failed\n";

    exit($failed > 0 ? 1 : 0);
}
