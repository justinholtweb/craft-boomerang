<?php
/**
 * Remove everything `seed.php` created.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web \
 *         php /var/www/craft-rma/tests/shots/teardown.php
 *
 * Exact rather than approximate: the seed tags everything it makes — SKUs start `BMR-`, emails
 * end `@boomerang.shots` — and nothing here deletes on a looser match than that.
 *
 * Credit is cleaned up by `customerId`, never by cascade. Craft **soft**-deletes users, so the
 * `CASCADE` foreign key on `users.id` does not fire when a customer is removed — which is the
 * right behaviour for a wallet (a restored customer should get their balance back) and means a
 * teardown has to do it itself.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\User;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\elements\ReturnRequest;

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$db = Craft::$app->getDb();
$elements = Craft::$app->getElements();

function out(string $line): void
{
    echo $line . "\n";
}

out("\nReturns");

$users = User::find()->email('*@boomerang.shots')->status(null)->all();
$customerIds = array_map(static fn(User $u) => $u->id, $users);

$returnIds = (new craft\db\Query())
    ->select(['id'])
    ->from(Table::RETURNS)
    ->where(['like', 'email', '@boomerang.shots'])
    ->column();

foreach ($returnIds as $id) {
    $return = ReturnRequest::find()->id($id)->status(null)->one();

    if ($return !== null) {
        $elements->deleteElement($return, true);
    }
}

out('  - ' . count($returnIds) . ' return(s)');

out("\nCredit");

if ($customerIds !== []) {
    $entries = $db->createCommand()->delete(Table::CREDITENTRIES, ['customerId' => $customerIds])->execute();
    $lots = $db->createCommand()->delete(Table::CREDITLOTS, ['customerId' => $customerIds])->execute();
    out("  - $lots lot(s), $entries entr(ies)");
} else {
    out('  - nothing to remove');
}

out("\nOrders");

$orders = Order::find()->email('*@boomerang.shots')->status(null)->limit(null)->all();

foreach ($orders as $order) {
    $elements->deleteElement($order, true);
}

out('  - ' . count($orders) . ' order(s)');

out("\nCatalog");

$variants = Variant::find()->sku('BMR-*')->status(null)->limit(null)->all();
$productIds = array_unique(array_map(static fn(Variant $v) => $v->getProduct()?->id, $variants));
$gone = 0;

foreach (array_filter($productIds) as $productId) {
    $product = Product::find()->id($productId)->status(null)->one();

    if ($product !== null) {
        $elements->deleteElement($product, true);
        $gone++;
    }
}

out("  - $gone product(s)");

out("\nCustomers");

foreach ($users as $user) {
    $elements->deleteElement($user, true);
}

out('  - ' . count($users) . ' customer(s)');

out("\nTorn down.\n");
