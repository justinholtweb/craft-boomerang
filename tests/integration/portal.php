<?php
/**
 * The anonymous returns portal, driven over HTTP the way a customer (or a script) would.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/integration/portal.php
 *
 * `checks.php` exercises the services; this posts the portal's own forms, because what the portal
 * accepts is decided in its controller — which lines, how many, and which files. It persists the
 * settings the portal needs (a separate process reads them) and restores them on the way out.
 *
 * Idempotent and self-cleaning: products, the order, returns and uploaded photos are removed.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

require __DIR__ . '/_fixtures.php';

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$originalSettings = $plugin->getSettings()->toArray();
$volume = Craft::$app->getVolumes()->getAllVolumes()[0];
$excludedSku = "BOOM-X-$run";
$photoFilenames = ["boomerang-portal-$run.png", "boomerang-portal-$run.html"];

register_shutdown_function(function() use (&$createdOrders, &$createdProducts, $originalSettings, $photoFilenames, $run) {
    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $order) {
        foreach (ReturnRequest::find()->orderId($order->id)->status(null)->all() as $return) {
            $elements->deleteElement($return, true);
        }

        if ($fresh = Order::find()->id($order->id)->status(null)->one()) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdProducts as $product) {
        if ($fresh = $elements->getElementById($product->id, Product::class)) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach (Asset::find()->filename("boomerang-portal-$run*")->status(null)->all() as $asset) {
        $elements->deleteElement($asset, true);
    }

    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $originalSettings);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

// Persisted, because the portal runs in another process. Auto-approval is on so that a line the
// portal wrongly accepted would go straight to "approved" — the worst case the audit described.
Craft::$app->getPlugins()->savePluginSettings($plugin, [
    'portalEnabled' => true,
    'portalAllowsPhotos' => true,
    'photoVolumeUid' => $volume->uid,
    'maxPhotosPerItem' => 5,
    'maxPhotoSize' => 2048,
    'portalRateLimit' => 0,
    'returnWindowDays' => 30,
    'requireCompletedOrder' => true,
    'requirePaidOrder' => true,
    'allowPartialQty' => true,
    'allowMultipleReturns' => true,
    'autoApprove' => true,
    'excludedSkus' => [$excludedSku],
    'notifyCustomer' => false,
    'notifyStaff' => false,
    'queueNotifications' => false,
] + $originalSettings);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$email = "boomerang-portal-$run@example.com";
$normal = makeProduct("BOOM-N-$run", 30.00);
$excluded = makeProduct($excludedSku, 20.00);
$order = makeOrder([['variant' => $normal, 'qty' => 1], ['variant' => $excluded, 'qty' => 1]], true, null, $email);
payOrder($order);
$order = Order::find()->id($order->id)->one();

$normalLine = null;
$excludedLine = null;

foreach ($order->getLineItems() as $line) {
    if ($line->sku === $excludedSku) {
        $excludedLine = $line;
    } else {
        $normalLine = $line;
    }
}

$reason = null;

foreach ($plugin->reasons->getCustomerReasons() as $candidate) {
    if (!$candidate->requiresPhoto) {
        $reason = $candidate;

        break;
    }
}

// A real one-pixel PNG, and an HTML page wearing a photo's name.
$pngPath = Craft::$app->getPath()->getTempPath() . "/portal-$run.png";
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='));
$htmlPath = Craft::$app->getPath()->getTempPath() . "/portal-$run.html";
file_put_contents($htmlPath, '<script>alert(document.cookie)</script>');

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$lookup = $http->post('index.php', ['form_params' => [
    'action' => 'boomerang/portal/lookup',
    'number' => $order->number,
    'email' => $email,
    'CRAFT_CSRF_TOKEN' => $csrf(),
]]);

$returnsFor = static fn() => ReturnRequest::find()->orderId($order->id)->status(null)->all();

/** Post the portal's submit form; `$files` is a list of [lineItemId, path, filename]. */
$submit = static function(array $items, array $files = []) use ($http, $csrf): int {
    $parts = [
        ['name' => 'action', 'contents' => 'boomerang/portal/submit'],
        ['name' => 'CRAFT_CSRF_TOKEN', 'contents' => $csrf()],
    ];

    foreach ($items as $lineItemId => $fields) {
        foreach ($fields as $key => $value) {
            $parts[] = ['name' => "items[$lineItemId][$key]", 'contents' => (string)$value];
        }
    }

    foreach ($files as [$lineItemId, $path, $filename]) {
        $parts[] = ['name' => "photos[$lineItemId][]", 'contents' => fopen($path, 'rb'), 'filename' => $filename];
    }

    return $http->post('index.php', ['multipart' => $parts])->getStatusCode();
};

section('Finding the order');

check('the portal finds the order by number and email', fn() => $lookup->getStatusCode() === 302 ?: 'status ' . $lookup->getStatusCode());

section('What the portal will take back');

check('a line excluded from returns is refused, even when posted directly', function() use ($submit, $excludedLine, $reason, $returnsFor) {
    $status = $submit([$excludedLine->id => ['qty' => 1, 'reasonId' => $reason->id, 'comment' => 'Posted by hand']]);

    return $status !== 302 && $returnsFor() === [] ?: "status $status, " . count($returnsFor()) . ' return(s)';
});

check('a returnable line is accepted, capped at what was bought, with only the real photo kept', function() use ($submit, $normalLine, $reason, $returnsFor, $pngPath, $htmlPath, $photoFilenames) {
    $status = $submit(
        [$normalLine->id => ['qty' => 5, 'reasonId' => $reason->id, 'comment' => 'Too big']],
        [[$normalLine->id, $pngPath, $photoFilenames[0]], [$normalLine->id, $htmlPath, $photoFilenames[1]]],
    );
    $returns = $returnsFor();
    $item = $returns[0]->getItems()[0] ?? null;
    $photos = Asset::find()->id($item?->photoIds ?: [0])->status(null)->all();
    $names = array_map(static fn(Asset $a) => $a->getFilename(), $photos);

    return $status === 302
        && count($returns) === 1
        && $item->qtyRequested === 1
        && count($photos) === 1
        && str_ends_with($names[0], '.png')
        ?: sprintf('status %d, %d return(s), qty %s, photos %s', $status, count($returns), $item?->qtyRequested ?? '-', json_encode($names));
});

check('no HTML file reached the volume', function() use ($run) {
    return !Asset::find()->filename("boomerang-portal-$run*.html")->status(null)->exists() ?: 'it did';
});

check('the same line cannot be returned a second time', function() use ($submit, $normalLine, $reason, $returnsFor) {
    $status = $submit([$normalLine->id => ['qty' => 1, 'reasonId' => $reason->id, 'comment' => 'Again']]);

    return $status !== 302 && count($returnsFor()) === 1 ?: "status $status, " . count($returnsFor()) . ' return(s)';
});

check('a refused request leaves no photos behind', function() use ($submit, $excludedLine, $reason, $pngPath, $run) {
    $before = Asset::find()->filename("boomerang-portal-$run*")->status(null)->count();
    $submit([$excludedLine->id => ['qty' => 1, 'reasonId' => $reason->id, 'comment' => 'x']], [[$excludedLine->id, $pngPath, "boomerang-portal-$run-orphan.png"]]);

    return Asset::find()->filename("boomerang-portal-$run*")->status(null)->count() === $before ?: 'an orphan was stored';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
