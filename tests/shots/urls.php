<?php
/**
 * Print the control-panel URLs the capture spec needs.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web \
 *         php /var/www/craft-rma/tests/shots/urls.php
 *
 * Element IDs are not stable across a teardown and re-seed — every run makes new elements — so
 * `~/Sites/plugin-shots/specs/boomerang.json` has to be re-pointed whenever the seed is rebuilt.
 * A detail URL for a deleted element does not error in the capture run; it 404s, the frame falls
 * back to the whole page, and the shot looks plausible until somebody reads it.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\boomerang\db\Table;

Craft::$app->getPlugins()->loadPlugins();

$returns = (new craft\db\Query())
    ->select(['r.id', 'r.reference', 'r.resolution', 's.name AS state'])
    ->from(['r' => Table::RETURNS])
    ->leftJoin(['s' => Table::STATES], '[[s.id]] = [[r.stateId]]')
    ->where(['like', 'r.email', '@boomerang.shots'])
    ->orderBy(['r.id' => SORT_ASC])
    ->all();

echo "\nReturns\n";

foreach ($returns as $row) {
    printf("  /admin/boomerang/returns/%-7d %-12s %-10s %s\n", $row['id'], $row['reference'], $row['state'], $row['resolution']);
}

$customers = (new craft\db\Query())
    ->select(['l.customerId', 'u.email'])
    ->from(['l' => Table::CREDITLOTS])
    ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[l.customerId]]')
    ->where(['like', 'u.email', '@boomerang.shots'])
    ->groupBy(['l.customerId', 'u.email'])
    ->all();

echo "\nCredit\n";

foreach ($customers as $row) {
    printf("  /admin/boomerang/credit/%-7d %s\n", $row['customerId'], $row['email']);
}

echo "\n";
