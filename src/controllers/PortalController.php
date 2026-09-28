<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Asset;
use craft\errors\UploadFailedException;
use craft\helpers\Assets;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The customer-facing returns portal.
 *
 * ## Two ways in, and only two
 *
 * A signed-in customer sees their own orders. A guest looks an order up by **number and email
 * together** — never the number alone, because an order number in a URL that returns an address
 * and a shopping history is a data breach with a nice interface. The looked-up order is held in
 * the session rather than passed back through the URL, so the address never appears in a browser
 * history, a proxy log or a shared link.
 *
 * An existing return is reached by its own 32-character token, compared in constant time.
 *
 * ## Templates
 *
 * Everything here renders `boomerang/_portal/*`, which resolves to the site's own templates folder
 * first and the plugin's shipped ones second. A merchant restyles the portal by copying a file;
 * nothing has to be configured, and the URLs do not move.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    private const SESSION_ORDER = 'boomerang.orderId';

    /** What a return photo may be. HEIC is what an iPhone sends by default. */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];

    private const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->portalEnabled) {
            throw new NotFoundHttpException();
        }

        if ($settings->portalRequiresLogin) {
            $this->requireLogin();
        }

        return true;
    }

    /**
     * The way in: a lookup form, or the customer's own orders and returns.
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $this->renderPortal('index', [
            'user' => $user,
            'orders' => $user !== null ? $this->ordersFor($user->id) : [],
            'returns' => $user !== null
                ? ReturnRequest::find()->customerId($user->id)->isArchived(null)->all()
                : [],
        ]);
    }

    /**
     * Find an order from an order number and an email address.
     */
    public function actionLookup(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        $number = trim((string)$this->request->getBodyParam('number', ''));
        $email = trim((string)$this->request->getBodyParam('email', ''));

        // The lookup is the guessable half of the portal, so it is rate limited too — not just
        // the submission.
        if (!$this->underRateLimit('lookup')) {
            return $this->renderPortal('index', [
                'error' => Craft::t('boomerang', 'Too many attempts. Try again in a little while.'),
                'number' => $number,
            ]);
        }

        $order = $plugin->eligibility->findOrderForLookup($number, $email);

        if ($order === null) {
            // Deliberately one message for "no such order" and "wrong email": telling them apart
            // turns the form into an order-number oracle.
            return $this->renderPortal('index', [
                'error' => Craft::t('boomerang', 'We couldn’t find an order with that number and email address.'),
                'number' => $number,
                'email' => $email,
            ]);
        }

        Craft::$app->getSession()->set(self::SESSION_ORDER, $order->id);

        return $this->redirect(UrlHelper::siteUrl($this->prefix() . '/start'));
    }

    /**
     * The request form for the order the customer has proved they own.
     */
    public function actionStart(): Response
    {
        $plugin = Plugin::getInstance();
        $order = $this->currentOrder();

        if ($order === null) {
            return $this->redirect(UrlHelper::siteUrl($this->prefix()));
        }

        return $this->renderPortal('start', [
            'order' => $order,
            'eligibility' => $plugin->eligibility->evaluate($order),
            'reasons' => $plugin->reasons->getCustomerReasons(),
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * Make the return.
     */
    public function actionSubmit(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $order = $this->currentOrder();

        if ($order === null) {
            throw new BadRequestHttpException('No order');
        }

        if (!$this->underRateLimit('submit')) {
            return $this->renderPortal('start', [
                'order' => $order,
                'eligibility' => $plugin->eligibility->evaluate($order),
                'reasons' => $plugin->reasons->getCustomerReasons(),
                'settings' => $plugin->getSettings(),
                'error' => Craft::t('boomerang', 'Too many attempts. Try again in a little while.'),
            ]);
        }

        $verdict = $plugin->eligibility->evaluate($order);

        if (!$verdict->isEligible) {
            return $this->renderPortal('start', [
                'order' => $order,
                'eligibility' => $verdict,
                'reasons' => $plugin->reasons->getCustomerReasons(),
                'settings' => $plugin->getSettings(),
                'error' => $verdict->getFirstMessage(),
            ]);
        }

        $lines = $this->postedLines();
        $errors = $this->validateLines($lines, $verdict);

        if ($lines === [] && $errors === []) {
            $errors[] = Craft::t('boomerang', 'Choose at least one item to send back.');
        }

        if ($errors !== []) {
            return $this->renderPortal('start', [
                'order' => $order,
                'eligibility' => $verdict,
                'reasons' => $plugin->reasons->getCustomerReasons(),
                'settings' => $plugin->getSettings(),
                'errors' => $errors,
                'posted' => $lines,
            ]);
        }

        // Only now, with every line accepted: a request that fails validation must not leave the
        // customer's files behind in the volume.
        foreach ($lines as $lineItemId => $line) {
            $lines[$lineItemId]['photoIds'] = $this->storePhotos($this->acceptablePhotos((int)$lineItemId));
        }

        $return = $plugin->returns->create($order, $lines, [
            'customerNote' => trim((string)$this->request->getBodyParam('note')) ?: null,
        ]);

        if (!$plugin->returns->submit($return, byCustomer: true)) {
            return $this->renderPortal('start', [
                'order' => $order,
                'eligibility' => $verdict,
                'reasons' => $plugin->reasons->getCustomerReasons(),
                'settings' => $plugin->getSettings(),
                'errors' => $return->getErrorSummary(true),
            ]);
        }

        Craft::$app->getSession()->remove(self::SESSION_ORDER);

        return $this->redirect(UrlHelper::siteUrl($this->prefix() . '/status', ['rma' => $return->portalToken]));
    }

    /**
     * Where the customer watches their return.
     */
    public function actionStatus(): Response
    {
        $return = $this->currentReturn();

        return $this->renderPortal('status', [
            'return' => $return,
            'state' => $return->getState(),
            'items' => $return->getItems(),
            'events' => Plugin::getInstance()->returns->getEventsForReturn((int)$return->id, customerVisibleOnly: true),
            'label' => $return->getActiveLabel(),
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    /**
     * The customer calling it off.
     */
    public function actionCancel(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $return = $this->currentReturn();

        if (!$return->getIsCustomerCancellable()) {
            throw new ForbiddenHttpException(Craft::t('boomerang', 'This return can no longer be cancelled.'));
        }

        $plugin->returns->cancel($return, [
            'byCustomer' => true,
            'force' => true,
            'message' => Craft::t('boomerang', 'Cancelled by the customer.'),
        ]);

        return $this->redirect(UrlHelper::siteUrl($this->prefix() . '/status', ['rma' => $return->portalToken]));
    }

    /**
     * The customer asking for a label, when the state allows it.
     */
    public function actionRequestLabel(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $return = $this->currentReturn();

        if (!($return->getState()?->offerLabel ?? false)) {
            throw new ForbiddenHttpException(Craft::t('boomerang', 'A label isn’t available for this return yet.'));
        }

        if ($return->getActiveLabel() !== null) {
            return $this->redirect(UrlHelper::siteUrl($this->prefix() . '/status', ['rma' => $return->portalToken]));
        }

        try {
            $plugin->labels->create($return);
        } catch (\Throwable $e) {
            Craft::warning('Boomerang could not create a portal label: ' . $e->getMessage(), __METHOD__);

            Craft::$app->getSession()->setError(Craft::t('boomerang', 'We couldn’t create a label just now. Please get in touch.'));
        }

        return $this->redirect(UrlHelper::siteUrl($this->prefix() . '/status', ['rma' => $return->portalToken]));
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * The order the customer has proved they own — from the session, or from their account.
     */
    private function currentOrder(): ?Order
    {
        $orderId = $this->request->getParam('orderId');

        if ($orderId !== null) {
            $user = Craft::$app->getUser()->getIdentity();

            if ($user === null) {
                throw new ForbiddenHttpException('Not allowed');
            }

            $order = Order::find()->id((int)$orderId)->isCompleted(true)->status(null)->one();

            // Ownership is checked against the account, not against the URL.
            if ($order === null || $order->getCustomerId() !== $user->id) {
                throw new ForbiddenHttpException('Not allowed');
            }

            return $order;
        }

        $sessionOrderId = Craft::$app->getSession()->get(self::SESSION_ORDER);

        return $sessionOrderId ? Order::find()->id((int)$sessionOrderId)->status(null)->one() : null;
    }

    /**
     * The return the request is entitled to see.
     */
    private function currentReturn(): ReturnRequest
    {
        $token = (string)$this->request->getParam('rma', '');
        $plugin = Plugin::getInstance();

        if ($token !== '') {
            $return = $plugin->returns->getReturnByToken($token);

            // Constant-time, because this token is the only thing between a stranger and somebody
            // else's return.
            if ($return !== null && $return->portalToken !== null && hash_equals($return->portalToken, $token)) {
                return $return;
            }

            throw new NotFoundHttpException();
        }

        $returnId = $this->request->getParam('returnId');
        $user = Craft::$app->getUser()->getIdentity();

        if ($returnId !== null && $user !== null) {
            $return = Craft::$app->getElements()->getElementById((int)$returnId, ReturnRequest::class);

            if ($return !== null && $return->customerId === $user->id) {
                return $return;
            }
        }

        throw new NotFoundHttpException();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function postedLines(): array
    {
        $posted = (array)$this->request->getBodyParam('items', []);
        $lines = [];

        foreach ($posted as $lineItemId => $row) {
            $qty = (int)($row['qty'] ?? 0);

            if ($qty < 1) {
                continue;
            }

            $lines[(int)$lineItemId] = [
                'qty' => $qty,
                'reasonId' => isset($row['reasonId']) ? (int)$row['reasonId'] : null,
                'comment' => isset($row['comment']) ? trim((string)$row['comment']) : null,
                // Filled in by actionSubmit() once the whole request has validated.
                'photoIds' => [],
            ];
        }

        return $lines;
    }

    /**
     * Everything eligibility and the reasons insisted on.
     *
     * Eligibility is checked per line, not just for the order: the form only offers returnable
     * lines, but the request is a list of line IDs and quantities anyone can edit. Without this a
     * customer could post an excluded or final-sale line, or one already sent back in full, and get
     * an RMA for it — one that auto-approval would then turn into a refund.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return string[]
     */
    private function validateLines(array &$lines, \justinholtweb\boomerang\models\Eligibility $verdict): array
    {
        $plugin = Plugin::getInstance();
        $errors = [];

        foreach ($lines as $lineItemId => $line) {
            $available = $verdict->availableQty((int)$lineItemId);

            if ($available < 1) {
                $errors[] = Craft::t('boomerang', '“{item}” can’t be returned.', [
                    'item' => $verdict->getLine((int)$lineItemId)?->description ?: Craft::t('boomerang', 'That item'),
                ]);

                continue;
            }

            // Never more than can still be sent back, whatever was posted.
            $lines[$lineItemId]['qty'] = min((int)$line['qty'], $available);

            $reason = $line['reasonId'] ? $plugin->reasons->getReasonById((int)$line['reasonId']) : null;

            if ($reason === null || !$reason->enabled || !$reason->customerSelectable) {
                $errors[] = Craft::t('boomerang', 'Choose a reason for every item you’re sending back.');

                continue;
            }

            if ($reason->requiresComment && ($line['comment'] ?? '') === '') {
                $errors[] = Craft::t('boomerang', '“{reason}” needs a short explanation.', ['reason' => $reason->name]);
            }

            if ($reason->requiresPhoto && $this->acceptablePhotos((int)$lineItemId) === []) {
                $errors[] = Craft::t('boomerang', '“{reason}” needs a photo.', ['reason' => $reason->name]);
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * The files posted for a line that are images Boomerang will keep. Nothing is stored here.
     *
     * The portal is anonymous and these land in an asset volume, so the rule is "a photo", not
     * "anything Craft would accept": Craft's own list includes `html`, `svg` and `js`, and on a
     * volume served from the site's origin any of those opens as script in a staff member's
     * browser. The extension has to be an image one and the contents have to agree.
     *
     * @return UploadedFile[]
     */
    private function acceptablePhotos(int $lineItemId): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->portalAllowsPhotos || $settings->photoVolumeUid === '') {
            return [];
        }

        $allowed = array_intersect(
            self::PHOTO_EXTENSIONS,
            array_map('strtolower', Craft::$app->getConfig()->getGeneral()->allowedFileExtensions),
        );
        $accepted = [];

        foreach (UploadedFile::getInstancesByName("photos.$lineItemId") as $file) {
            if ($file->getHasError() || $file->size > $settings->maxPhotoSize * 1024) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), $allowed, true)) {
                continue;
            }

            $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($file->tempName);

            if (!in_array($mime, self::PHOTO_MIME_TYPES, true)) {
                continue;
            }

            $accepted[] = $file;

            if (count($accepted) >= max(1, $settings->maxPhotosPerItem)) {
                break;
            }
        }

        return $accepted;
    }

    /**
     * Save accepted photos as assets in the configured volume.
     *
     * @param UploadedFile[] $files
     * @return int[]
     */
    private function storePhotos(array $files): array
    {
        $volume = Craft::$app->getVolumes()->getVolumeByUid(Plugin::getInstance()->getSettings()->photoVolumeUid);

        if ($volume === null || $files === []) {
            return [];
        }

        $folderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
        $ids = [];

        foreach ($files as $file) {
            try {
                $asset = new Asset();
                $asset->tempFilePath = $file->tempName;
                $asset->setFilename(Assets::prepareAssetName($file->name));
                $asset->newFolderId = $folderId;
                $asset->setVolumeId($volume->id);
                $asset->avoidFilenameConflicts = true;
                $asset->setScenario(Asset::SCENARIO_CREATE);

                if (Craft::$app->getElements()->saveElement($asset)) {
                    $ids[] = (int)$asset->id;
                }
            } catch (UploadFailedException|\Throwable $e) {
                Craft::warning('Boomerang could not store a portal photo: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $ids;
    }

    /**
     * Attempts per IP per hour, in Craft's cache.
     *
     * Anonymous, so cheap to abuse: without this the lookup form is a way to test order numbers
     * against email addresses at whatever rate the server will take.
     */
    private function underRateLimit(string $bucket): bool
    {
        $limit = Plugin::getInstance()->getSettings()->portalRateLimit;

        if ($limit < 1) {
            return true;
        }

        $ip = $this->request->getUserIP() ?? 'unknown';
        $key = sprintf('boomerang:%s:%s:%s', $bucket, md5($ip), date('YmdH'));
        $cache = Craft::$app->getCache();

        $count = (int)$cache->get($key);

        if ($count >= $limit) {
            return false;
        }

        $cache->set($key, $count + 1, 3600);

        return true;
    }

    private function prefix(): string
    {
        return trim(Plugin::getInstance()->getSettings()->portalUriPrefix, '/');
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderPortal(string $template, array $variables = []): Response
    {
        return $this->renderTemplate('boomerang/_portal/' . $template, $variables + [
            'plugin' => Plugin::getInstance(),
            'portalPrefix' => $this->prefix(),
            'portalUrl' => UrlHelper::siteUrl($this->prefix()),
        ]);
    }

    /**
     * @return Order[]
     */
    private function ordersFor(int $userId): array
    {
        return Order::find()
            ->customerId($userId)
            ->isCompleted(true)
            ->status(null)
            ->orderBy(['dateOrdered' => SORT_DESC])
            ->limit(25)
            ->all();
    }
}
