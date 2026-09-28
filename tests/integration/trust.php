<?php
/**
 * Who may write the Twig in a state's email — checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/integration/trust.php
 *
 * A state's email subject and body are object templates, run on every state change with the whole
 * of Craft in reach. Until 5.0.1 anyone with "configure states and reasons" could set them. This
 * signs in as such a user (not an admin), edits a state, and reads back what was stored.
 *
 * Idempotent and self-cleaning: the user is removed and the state is put back as it was.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\boomerang\Plugin;

Craft::$app->getPlugins()->loadPlugins();

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

$plugin = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'rma-' . bin2hex(random_bytes(12));
$state = $plugin->states->getDefaultState();
$original = ['subject' => $state->emailSubject, 'body' => $state->emailBody, 'description' => $state->description];
$user = null;

register_shutdown_function(function() use (&$user, $state, $original) {
    $fresh = (new \justinholtweb\boomerang\services\States())->getStateById((int)$state->id);
    $fresh->emailSubject = $original['subject'];
    $fresh->emailBody = $original['body'];
    $fresh->description = $original['description'];
    Plugin::getInstance()->states->saveState($fresh);

    if ($user !== null) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

$user = new User();
$user->username = "rma-configurer-$run";
$user->email = "rma-configurer-$run@example.com";
$user->newPassword = $password;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
    'accesscp', 'accessplugin-boomerang', strtolower(Plugin::PERMISSION_CONFIGURE),
]);

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

echo "\nState emails\n";

check('a non-admin can save a state but not change its email Twig', function() use ($http, $csrf, $state, $plugin, $run) {
    $response = $http->post('index.php?p=admin/actions/boomerang/states/save', ['form_params' => [
        'stateId' => $state->id,
        'name' => $state->name,
        'handle' => $state->handle,
        'kind' => $state->kind,
        'color' => (string)$state->color,
        'description' => "Edited by trust.php $run",
        'isDefault' => $state->isDefault ? '1' : '',
        'notifyCustomer' => $state->notifyCustomer ? '1' : '',
        'emailSubject' => '{{ craft.app.config.general.securityKey }}',
        'emailBody' => '{{ craft.users.all()|length }}',
        'transitions' => $state->transitions,
        'CRAFT_CSRF_TOKEN' => $csrf(),
    ]]);

    // A new service instance, because the plugin's memoizes its list for the life of the process.
    $fresh = (new \justinholtweb\boomerang\services\States())->getStateById((int)$state->id);

    return $fresh->description === "Edited by trust.php $run"
        && $fresh->emailSubject === $state->emailSubject
        && $fresh->emailBody === $state->emailBody
        ?: sprintf('status %d, description %s, subject %s', $response->getStatusCode(), var_export($fresh->description, true), var_export($fresh->emailSubject, true));
});

check('the state screen shows the email fields locked', function() use ($http, $state) {
    $html = (string)$http->get("admin/boomerang/states/{$state->id}")->getBody();

    return str_contains($html, 'Only admins can change the email subject and body')
        && preg_match('/name="emailSubject"[^>]*disabled|disabled[^>]*name="emailSubject"/', $html) === 1
        ?: 'not locked';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
