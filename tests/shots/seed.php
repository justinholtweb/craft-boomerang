<?php
/**
 * Drive the plugin-testing harness into a state worth photographing.
 *
 * Run inside the container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web \
 *         php /var/www/craft-boomerang/tests/shots/seed.php
 *
 * Unlike `tests/integration/checks.php`, this is **not** self-cleaning: the whole point is to
 * leave a returns desk standing so the control panel has something to show. `teardown.php`
 * removes it again, and everything created here is tagged — SKUs start `BMR-`, emails end
 * `@boomerang.shots` — so teardown can be exact rather than approximate.
 *
 * Every RMA here is moved with `Returns::transition()` rather than by writing `stateId`. That is
 * slower and it fires real emails, real restocks and real refunds — which is the point. The
 * history panel is one of the screens being photographed, and an RMA whose state was set behind
 * the service's back has no history at all.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\enums\Resolution;
use justinholtweb\boomerang\Plugin;

// Plugins load lazily in a bare console bootstrap, so ask explicitly rather than depend on what
// this file happens to touch first.
Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();

if ($plugin === null) {
    fwrite(STDERR, "Boomerang is not loaded — is it installed and enabled in this harness?\n");

    exit(1);
}

// The wallet, labels and analytics screens are Pro. In memory only: project config is contended
// in this shared harness, and a script that writes it fails for reasons that have nothing to do
// with the plugin.
$plugin->edition = Plugin::EDITION_PRO;

// Two sibling plugins in this shared harness break element saves and order completion outright.
// Detached in-process, never persisted — see the same block in tests/integration/checks.php.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);

    yii\base\Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(yii\base\Event $event) use ($plugin) {
        $plugin->exchanges->settleRemainder($event->sender);
    });

    echo "  ! detached craft-lyfe's order-complete handler for this run\n";
}

$storeId = $commerce->getStores()->getPrimaryStore()->id;
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$productType = $commerce->getProductTypes()->getAllProductTypes()[0];

/** A shop worth photographing: real titles, real prices, nothing called "fixture". */
const CATALOG = [
    ['BMR-TRAIL-40',  'Trailhead 40L Pack',        189.00],
    ['BMR-MERINO-CR', 'Merino Crew, Heather',       98.00],
    ['BMR-RAIN-2L',   'Shelter 2L Rain Jacket',    245.00],
    ['BMR-BOOT-MID',  'Ridgeline Mid Boot',        215.00],
    ['BMR-DOWN-800',  'Featherline 800 Down Hood', 320.00],
    ['BMR-GLOVE-LW',  'Liner Glove, Lightweight',   38.00],
    ['BMR-BOTTLE-1L', 'Vacuum Bottle, 1L',          44.00],
];

/** Four people, so the credit index has more than one row in it. */
const PEOPLE = [
    ['maya.okonkwo@boomerang.shots',  'Maya',   'Okonkwo'],
    ['tomas.reinhardt@boomerang.shots', 'Tomas', 'Reinhardt'],
    ['priya.balakrishnan@boomerang.shots', 'Priya', 'Balakrishnan'],
    ['elena.vasquez@boomerang.shots', 'Elena',  'Vasquez'],
];

function out(string $line): void
{
    echo $line . "\n";
}

// ---------------------------------------------------------------------------------------------
// Catalog
// ---------------------------------------------------------------------------------------------
out("\nCatalog");

$variants = [];

foreach (CATALOG as [$sku, $title, $price]) {
    $existing = Variant::find()->sku($sku)->status(null)->one();

    if ($existing !== null) {
        $variants[$sku] = $existing;
        out("  = $sku already there");

        continue;
    }

    $product = new Product();
    $product->typeId = $productType->id;
    $product->title = $title;
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = 1.2;
    $variant->isDefault = true;
    $variant->inventoryTracked = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException("Could not save $sku: " . json_encode($product->getErrors()));
    }

    $variant = Variant::find()->sku($sku)->one();
    $commerce->getInventory()->updatePurchasableInventoryLevel($variant, 24);

    $variants[$sku] = $variant;
    out("  + $sku — $title");
}

// ---------------------------------------------------------------------------------------------
// Customers
// ---------------------------------------------------------------------------------------------
out("\nCustomers");

$people = [];

foreach (PEOPLE as [$email, $first, $last]) {
    $user = User::find()->email($email)->status(null)->one();

    if ($user === null) {
        $user = new User();
        $user->email = $email;
        $user->username = $email;
        $user->firstName = $first;
        $user->lastName = $last;
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user, false)) {
            throw new RuntimeException("Could not save $email: " . json_encode($user->getErrors()));
        }

        out("  + $first $last");
    } else {
        out("  = $first $last already there");
    }

    $people[$email] = $user;
}

// ---------------------------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------------------------

/**
 * A completed, paid order — paid because a return that resolves to a refund needs something to
 * refund against, and Commerce refuses one otherwise.
 */
function makeOrder(User $customer, array $lines, string $placedDaysAgo): Order
{
    global $commerce, $storeId, $siteId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = $siteId;
    $order->number = $commerce->getCarts()->generateCartNumber();
    $order->setEmail($customer->email);
    $order->setCustomerId($customer->id);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $lineItems = [];

    foreach ($lines as [$variant, $qty]) {
        $lineItems[] = $commerce->getLineItems()->createLineItem($order, $variant->id, [], $qty);
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, and `toArray()` carries the original's
    // IDs — which is exactly what trips the check. A plain attribute array lets Commerce build the
    // owned element itself.
    $address = [
        'fullName' => $customer->firstName . ' ' . $customer->lastName,
        'addressLine1' => '1120 Central Avenue',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28204',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

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

    $order->markAsComplete();

    $transaction = $commerce->getTransactions()->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
    $transaction->status = TransactionRecord::STATUS_SUCCESS;
    $transaction->reference = 'bmr-shots-' . uniqid('', false);
    $transaction->amount = $order->getTotalPrice();
    $transaction->paymentAmount = $order->getTotalPrice();

    if (!$commerce->getTransactions()->saveTransaction($transaction)) {
        throw new RuntimeException('Could not save the payment.');
    }

    $order->updateOrderPaidInformation();

    // Backdate the order so the RMA list does not read as though the whole shop returned
    // everything this morning.
    $placed = (new DateTime())->modify($placedDaysAgo);
    Craft::$app->getDb()->createCommand()
        ->update('{{%commerce_orders}}', ['dateOrdered' => craft\helpers\Db::prepareDateForDb($placed)], ['id' => $order->id])
        ->execute();

    return $order;
}

out("\nOrders and returns");

$states = $plugin->states;
$reasons = $plugin->reasons;

/**
 * Create an RMA against an order and walk it along the state machine, one real transition at a
 * time, so the history panel has something in it.
 *
 * @param array<int, array{0: int, 1: string}> $lines  line index => reason handle
 * @param array<int, string> $path                      state handles, in the order they happened
 * @param int $daysAgo                                  how long ago the customer asked
 */
function makeReturn(Order $order, array $lines, array $path, ?Resolution $resolution, int $daysAgo = 7, array $attributes = []): void
{
    global $plugin, $states;

    $lineItems = $order->getLineItems();
    $payload = [];

    foreach ($lines as [$index, $reasonHandle, $qty, $comment]) {
        $lineItem = $lineItems[$index];
        $reason = $plugin->reasons->getReasonByHandle($reasonHandle);

        $payload[$lineItem->id] = [
            'qty' => $qty,
            'reasonId' => $reason?->id,
            'comment' => $comment,
        ];
    }

    if ($resolution !== null) {
        $attributes['resolution'] = $resolution->value;
    }

    $return = $plugin->returns->create($order, $payload, $attributes);

    if (!$plugin->returns->submit($return)) {
        throw new RuntimeException('Could not submit return: ' . json_encode($return->getErrors()));
    }

    foreach ($path as $handle) {
        $to = $states->getStateByHandle($handle);

        if ($to === null) {
            throw new RuntimeException("No state '$handle' in this install.");
        }

        if (!$plugin->returns->transition($return, $to)) {
            $errors = json_encode($return->getErrors());
            out("    ! {$return->reference} refused the move to $handle: $errors");

            break;
        }
    }

    // Backdate the whole RMA. Without this every row in the index is stamped with the minute the
    // seed ran, and a returns desk where nine returns arrived in the same minute is the one thing
    // a screenshot of a returns desk must not show.
    backdate($return, $daysAgo);

    $state = $states->getStateById($return->stateId);
    out("  + {$return->reference} — {$state?->name}" . ($resolution ? " ({$resolution->value})" : ''));
}

/**
 * Push an RMA, its element row and its event log back in time.
 *
 * The events are spread across the same window rather than all moved by one offset, so the
 * history panel reads as a return that took a fortnight instead of one that happened at once.
 */
function backdate(justinholtweb\boomerang\elements\ReturnRequest $return, int $daysAgo): void
{
    $db = Craft::$app->getDb();
    $requested = (new DateTime())->modify("-$daysAgo days");

    $stamps = ['requestedAt' => craft\helpers\Db::prepareDateForDb($requested)];

    foreach (['approvedAt' => 1, 'receivedAt' => 5, 'resolvedAt' => 6] as $column => $offset) {
        if ($return->$column !== null) {
            $at = (clone $requested)->modify("+$offset days");
            $stamps[$column] = craft\helpers\Db::prepareDateForDb(min($at, new DateTime()));
        }
    }

    $stamps['stateChangedAt'] = end($stamps);

    $db->createCommand()->update(Table::RETURNS, $stamps, ['id' => $return->id])->execute();
    $db->createCommand()->update('{{%elements}}', ['dateCreated' => $stamps['requestedAt']], ['id' => $return->id])->execute();

    // Spread the event log across the window between the request and wherever it got to.
    $events = (new craft\db\Query())
        ->select(['id'])
        ->from(Table::EVENTS)
        ->where(['returnId' => $return->id])
        ->orderBy(['id' => SORT_ASC])
        ->column();

    $span = max(1, count($events) - 1);

    foreach (array_values($events) as $i => $eventId) {
        $hours = (int)round($i / $span * min($daysAgo, 6) * 24);
        $at = (clone $requested)->modify("+$hours hours");

        $db->createCommand()
            ->update(Table::EVENTS, ['dateCreated' => craft\helpers\Db::prepareDateForDb(min($at, new DateTime()))], ['id' => $eventId])
            ->execute();
    }
}

$maya = $people['maya.okonkwo@boomerang.shots'];
$tomas = $people['tomas.reinhardt@boomerang.shots'];
$priya = $people['priya.balakrishnan@boomerang.shots'];
$elena = $people['elena.vasquez@boomerang.shots'];

// A spread across the whole machine, so the index is not a column of one status.
$o1 = makeOrder($maya, [[$variants['BMR-RAIN-2L'], 1], [$variants['BMR-GLOVE-LW'], 2]], '-26 days');
makeReturn($o1, [[0, 'wrongSize', 1, 'Shoulders are tight over a fleece.']], ['approved', 'inTransit', 'received', 'resolved'], Resolution::Credit, 22);

$o2 = makeOrder($tomas, [[$variants['BMR-BOOT-MID'], 1]], '-19 days');
makeReturn($o2, [[0, 'arrivedDamaged', 1, 'Left boot has a split in the welt out of the box.']], ['approved', 'inTransit', 'received', 'resolved'], Resolution::Refund, 16);

$o3 = makeOrder($priya, [[$variants['BMR-DOWN-800'], 1], [$variants['BMR-BOTTLE-1L'], 1]], '-14 days');
makeReturn($o3, [[0, 'notAsDescribed', 1, 'Listed as 800 fill, tag says 700.']], ['approved', 'inTransit', 'received'], Resolution::Exchange, 11);

$o4 = makeOrder($elena, [[$variants['BMR-MERINO-CR'], 2]], '-11 days');
makeReturn($o4, [[0, 'wrongSize', 1, 'Ordered two sizes on purpose — keeping the medium.']], ['approved', 'inTransit'], Resolution::Refund, 8);

$o5 = makeOrder($maya, [[$variants['BMR-TRAIL-40'], 1]], '-9 days');
makeReturn($o5, [[0, 'faulty', 1, 'Hip belt buckle releases under load.']], ['approved'], Resolution::Replacement, 6);

$o6 = makeOrder($tomas, [[$variants['BMR-BOTTLE-1L'], 2], [$variants['BMR-GLOVE-LW'], 1]], '-6 days');
makeReturn($o6, [[0, 'changedMind', 1, 'Bought one locally in the meantime.']], [], Resolution::Credit, 4);

$o7 = makeOrder($priya, [[$variants['BMR-GLOVE-LW'], 3]], '-4 days');
makeReturn($o7, [[0, 'wrongItem', 2, 'Sent mediums, ordered smalls.']], ['approved'], Resolution::Refund, 3);

$o8 = makeOrder($elena, [[$variants['BMR-RAIN-2L'], 1]], '-3 days');
makeReturn($o8, [[0, 'arrivedLate', 1, 'Arrived after the trip it was for.']], ['rejected'], null, 2, ['staffNote' => 'Outside the 30-day window by four days; offered a discount code instead.']);

$o9 = makeOrder($maya, [[$variants['BMR-MERINO-CR'], 1]], '-2 days');
makeReturn($o9, [[0, 'other', 1, 'Pilling after one wash.']], [], Resolution::Credit, 1);

// Four more, deliberately sharing days with the nine above. Nine returns on nine separate days
// draws "returns per day" as a flat line pinned to the top of its own box, which reads as a
// broken chart rather than as a quiet fortnight.
$o10 = makeOrder($priya, [[$variants['BMR-MERINO-CR'], 1]], '-24 days');
makeReturn($o10, [[0, 'wrongSize', 1, 'Runs long in the body.']], ['approved', 'inTransit', 'received', 'resolved'], Resolution::Refund, 22);

$o11 = makeOrder($elena, [[$variants['BMR-BOTTLE-1L'], 1]], '-18 days');
makeReturn($o11, [[0, 'arrivedDamaged', 1, 'Dented in transit, lid will not seal.']], ['approved', 'inTransit', 'received', 'resolved'], Resolution::Credit, 16);

$o12 = makeOrder($maya, [[$variants['BMR-GLOVE-LW'], 2]], '-13 days');
makeReturn($o12, [[0, 'changedMind', 1, 'Ordered a warmer pair instead.']], ['approved', 'inTransit', 'received'], Resolution::Credit, 11);

$o13 = makeOrder($tomas, [[$variants['BMR-TRAIL-40'], 1]], '-5 days');
makeReturn($o13, [[0, 'notAsDescribed', 1, 'Listed at 40L, measures closer to 34L.']], ['approved'], Resolution::Refund, 3);

// ---------------------------------------------------------------------------------------------
// Store credit
// ---------------------------------------------------------------------------------------------
out("\nStore credit");

/**
 * Goodwill and gift-card lots on top of whatever the resolved returns issued.
 *
 * Maya gets four at different expiries and is then spent against, because the credit screen's
 * whole argument is that a balance is a stack of dated lots rather than one number — and a
 * customer whose every lot says "Never", issued on the same afternoon, argues the opposite.
 * The fifth column is how long ago the lot was issued.
 */
$lots = [
    [$maya, 30.00, '+12 days', 'Goodwill — delayed shipment in August.', 34],
    [$maya, 60.00, null, 'Loyalty credit, no expiry.', 20],
    [$maya, 25.00, '+45 days', 'Referral credit.', 9],
    [$tomas, 45.00, '+21 days', 'Goodwill — two weeks late out of the warehouse.', 12],
    [$priya, 120.00, '+180 days', 'Gift card, order BMR-GIFT-4471.', 27],
    [$elena, 18.00, '+5 days', 'Exchange difference, returned as credit.', 6],
];

foreach ($lots as [$user, $amount, $expiry, $note, $issuedDaysAgo]) {
    $options = ['note' => $note];

    if ($expiry !== null) {
        $options['expiresAt'] = (new DateTime())->modify($expiry);
    } else {
        $options['expiryDays'] = 0;
    }

    $lot = $plugin->wallet->issue($user->id, $amount, $options);

    // Backdate the lot and its issue entry, so "Issued" is a date rather than this afternoon.
    $issued = craft\helpers\Db::prepareDateForDb((new DateTime())->modify("-$issuedDaysAgo days"));
    Craft::$app->getDb()->createCommand()->update(Table::CREDITLOTS, ['dateCreated' => $issued], ['id' => $lot->id])->execute();
    Craft::$app->getDb()->createCommand()->update(Table::CREDITENTRIES, ['dateCreated' => $issued], ['lotId' => $lot->id])->execute();

    out(sprintf('  + $%0.2f to %s %s', $amount, $user->firstName, $expiry ? "(expires $expiry)" : '(no expiry)'));
}

// One spend, so the ledger has something other than issues in it and one lot is visibly part
// drawn down. It goes through `Wallet::spend()` rather than a hand-written entry: FIFO by expiry
// is the behaviour being illustrated, and a fixture that allocates by hand can illustrate it
// wrongly. The 12-day lot is the one that should go first.
$plugin->wallet->spend($maya->id, 42.00, 'shots:spend:maya:1', ['note' => 'Order INV-2026-01904.']);

// One spend is **several** entries — one per lot it had to draw from — each keyed with the lot
// appended to the caller's key. Matching the bare key updates nothing, silently.
$spentAt = craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-5 days'));
Craft::$app->getDb()->createCommand()
    ->update(Table::CREDITENTRIES, ['dateCreated' => $spentAt], ['like', 'idempotencyKey', 'shots:spend:maya:1'])
    ->execute();

out('  - $42.00 spent by Maya, oldest expiry first');

// Notifications are sent from a queue job, so the "Notification sent" events are written *after*
// every transition has returned — and therefore after each RMA was backdated. Drain the queue and
// re-align anything that landed in the future, or the history panel shows a fortnight of state
// changes followed by four emails sent this morning.
out("\nQueue");

Craft::$app->getQueue()->run();

$strays = (new craft\db\Query())
    ->select(['e.id', 'e.returnId', 'r.stateChangedAt'])
    ->from(['e' => Table::EVENTS])
    ->innerJoin(['r' => Table::RETURNS], '[[r.id]] = [[e.returnId]]')
    ->where(['like', 'r.email', '@boomerang.shots'])
    ->andWhere('[[e.dateCreated]] > [[r.stateChangedAt]]')
    ->all();

foreach ($strays as $stray) {
    Craft::$app->getDb()->createCommand()
        ->update(Table::EVENTS, ['dateCreated' => $stray['stateChangedAt']], ['id' => $stray['id']])
        ->execute();
}

out('  = ' . count($strays) . ' event(s) pulled back out of the future');

out("\nBalances");

foreach ($people as $email => $user) {
    out(sprintf('  %-34s $%0.2f', $user->firstName . ' ' . $user->lastName, $plugin->wallet->getBalance($user->id)));
}

out("\nSeeded. `teardown.php` removes all of it again.\n");
