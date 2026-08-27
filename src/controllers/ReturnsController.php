<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The returns desk.
 *
 * Thin, like every controller in the family: it resolves the RMA, checks the permission, and hands
 * the work to {@see \justinholtweb\boomerang\services\Returns}. Nothing here decides anything
 * about a return.
 */
class ReturnsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('boomerang/returns/_index', [
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionEdit(int $returnId): Response
    {
        $plugin = Plugin::getInstance();
        $return = $this->findReturn($returnId);

        return $this->renderTemplate('boomerang/returns/_detail', [
            'plugin' => $plugin,
            'return' => $return,
            'state' => $return->getState(),
            'items' => $return->getItems(),
            'events' => $return->getEvents(),
            'labels' => $return->getLabels(),
            'transitions' => $return->getState()?->getAvailableTransitions() ?? $plugin->states->getAllStates(),
            'allStates' => $plugin->states->getAllStates(),
            'resolutions' => $plugin->getSettings()->getAvailableResolutions(),
            'conditions' => ItemCondition::options(),
            'locations' => $plugin->isPro() ? $plugin->restock->getLocationOptions() : [],
            'locationOptions' => $this->locationOptions(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'canResolve' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_RESOLVE),
            'refundable' => $return->orderId !== null ? $plugin->refunds->getRefundableAmount($return->orderId) : 0.0,
        ]);
    }

    /**
     * Save the parts of an RMA a merchant edits by hand: resolution, notes, per-item quantities.
     *
     * Deliberately does not change state — that is `actionTransition`, so there is exactly one way
     * an RMA moves.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $return = $this->findReturn($request->getBodyParam('returnId'));

        $resolution = (string)$request->getBodyParam('resolution', $return->resolution);

        if ($resolution !== $return->resolution) {
            $this->requirePermission(Plugin::PERMISSION_RESOLVE);
        }

        if (isset($plugin->getSettings()->getAvailableResolutions()[$resolution])) {
            $return->resolution = $resolution;
        }

        $return->staffNote = trim((string)$request->getBodyParam('staffNote', $return->staffNote)) ?: null;

        $locationId = $request->getBodyParam('inventoryLocationId');
        $return->inventoryLocationId = $locationId !== null && $locationId !== '' ? (int)$locationId : null;

        $items = $return->getItems();
        $posted = (array)$request->getBodyParam('items', []);

        foreach ($items as $item) {
            $row = $posted[$item->id] ?? null;

            if ($row === null) {
                continue;
            }

            if (isset($row['qtyApproved'])) {
                $item->qtyApproved = max(0, min((int)$row['qtyApproved'], $item->qtyRequested));
            }

            if (isset($row['qtyReceived'])) {
                $item->qtyReceived = max(0, min((int)$row['qtyReceived'], $item->getResolvableQty()));
            }

            if (!empty($row['condition']) && ItemCondition::tryFrom((string)$row['condition']) !== null) {
                $item->condition = (string)$row['condition'];
            }

            if (isset($row['staffNote'])) {
                $item->staffNote = trim((string)$row['staffNote']) ?: null;
            }
        }

        $return->setItems($items);
        $return->setFieldValuesFromRequest('fields');

        // Recalculated from whatever the merchant just typed, so the number on screen after the
        // save is the number that will actually be paid.
        $return->resolutionAmount = $plugin->returns->calculateResolutionAmount($return);
        $return->feeAmount = $plugin->returns->calculateFee($return);

        if (!$plugin->returns->save($return)) {
            $this->setFailFlash(Craft::t('boomerang', 'Couldn’t save the return.'));
            Craft::$app->getUrlManager()->setRouteParams(['return' => $return]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Return saved.'));

        return $this->redirectToPostedUrl($return);
    }

    /**
     * Move an RMA. The only route that does.
     */
    public function actionTransition(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $return = $this->findReturn($this->request->getBodyParam('returnId'));

        $stateId = (int)$this->request->getRequiredBodyParam('stateId');
        $state = $plugin->states->getStateById($stateId);

        if ($state === null) {
            throw new NotFoundHttpException('State not found');
        }

        // Anything that pays a customer needs the money permission, not just the desk permission.
        if ($state->resolveOnEnter && $return->getResolution()->isTransacting()) {
            $this->requirePermission(Plugin::PERMISSION_RESOLVE);
        }

        $options = [
            'message' => trim((string)$this->request->getBodyParam('message')) ?: null,
            'force' => (bool)$this->request->getBodyParam('force', false),
        ];

        if (!$plugin->returns->transition($return, $state, $options)) {
            $message = $return->getFirstError('resolution')
                ?? $return->getFirstError('stateId')
                ?? Craft::t('boomerang', 'Couldn’t move the return.');

            if ($this->request->getAcceptsJson()) {
                return $this->asFailure($message);
            }

            $this->setFailFlash($message);

            return $this->redirect($return->getCpEditUrl());
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('boomerang', 'Return moved to {state}.', ['state' => $state->name]));
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Return moved to {state}.', ['state' => $state->name]));

        return $this->redirect($return->getCpEditUrl());
    }

    /**
     * Record what arrived and move the RMA into a received state in one go.
     */
    public function actionReceive(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $return = $this->findReturn($this->request->getBodyParam('returnId'));

        $locationId = $this->request->getBodyParam('inventoryLocationId');

        if ($locationId !== null && $locationId !== '') {
            $return->inventoryLocationId = (int)$locationId;
        }

        $received = (array)$this->request->getBodyParam('items', []);

        if (!$plugin->returns->receive($return, $received)) {
            $message = $return->getFirstError('resolution')
                ?? $return->getFirstError('stateId')
                ?? Craft::t('boomerang', 'Couldn’t receive the return.');

            $this->setFailFlash($message);

            return $this->redirect($return->getCpEditUrl());
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Return received.'));

        return $this->redirect($return->getCpEditUrl());
    }

    public function actionAddNote(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $return = $this->findReturn($this->request->getBodyParam('returnId'));
        $message = trim((string)$this->request->getRequiredBodyParam('message'));

        if ($message === '') {
            return $this->asFailure(Craft::t('boomerang', 'Write something first.'));
        }

        Plugin::getInstance()->returns->addNote($return, $message);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('boomerang', 'Note added.'));
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Note added.'));

        return $this->redirect($return->getCpEditUrl());
    }

    /**
     * Start a return from the CP, against an order.
     */
    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        $lines = $this->normalizeLines((array)$this->request->getBodyParam('lines', []));

        if ($lines === []) {
            // A merchant starting a return from the order screen usually means "all of it".
            foreach ($plugin->eligibility->evaluate($order)->getEligibleLines() as $line) {
                $lines[(int)$line->lineItemId] = ['qty' => $line->qtyAvailable];
            }
        }

        if ($lines === []) {
            $this->setFailFlash(Craft::t('boomerang', 'There is nothing on this order left to return.'));

            return $this->redirect($order->getCpEditUrl());
        }

        $return = $plugin->returns->create($order, $lines);

        if (!$plugin->returns->submit($return)) {
            $message = Craft::t('boomerang', 'Couldn’t create the return.');

            if ($this->request->getAcceptsJson()) {
                return $this->asFailure($message);
            }

            $this->setFailFlash($message);

            return $this->redirect($order->getCpEditUrl());
        }

        $message = Craft::t('boomerang', 'Return {reference} created.', ['reference' => $return->reference]);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message, ['cpEditUrl' => $return->getCpEditUrl()]);
        }

        $this->setSuccessFlash($message);

        return $this->redirect($return->getCpEditUrl());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $return = $this->findReturn($this->request->getBodyParam('returnId'));

        Craft::$app->getElements()->deleteElement($return);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Return deleted.'));

        return $this->redirect(UrlHelper::cpUrl('boomerang/returns'));
    }

    /**
     * Buy or attach a return label.
     */
    public function actionCreateLabel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $return = $this->findReturn($this->request->getBodyParam('returnId'));

        $params = [
            'trackingNumber' => $this->request->getBodyParam('trackingNumber'),
            'carrier' => $this->request->getBodyParam('carrier'),
            'service' => $this->request->getBodyParam('service'),
            'trackingUrl' => $this->request->getBodyParam('trackingUrl'),
            'labelUrl' => $this->request->getBodyParam('labelUrl'),
            'cost' => $this->request->getBodyParam('cost'),
            'assetId' => $this->request->getBodyParam('assetId'),
        ];

        try {
            $plugin->labels->create($return, array_filter($params, fn($value) => $value !== null && $value !== ''), $this->request->getBodyParam('provider'));
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect($return->getCpEditUrl());
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Label created.'));

        return $this->redirect($return->getCpEditUrl());
    }

    public function actionVoidLabel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $label = $plugin->labels->getLabelById((int)$this->request->getRequiredBodyParam('labelId'));

        if ($label === null) {
            throw new NotFoundHttpException('Label not found');
        }

        $plugin->labels->void($label);

        $this->setSuccessFlash(Craft::t('boomerang', 'Label voided.'));

        return $this->redirect(UrlHelper::cpUrl('boomerang/returns/' . $label->returnId));
    }

    /**
     * Inventory locations as a select's option list.
     *
     * Built here rather than in Twig because a Twig `merge` of a blank option onto an ID-keyed
     * hash renumbers the integer keys, which silently rewrites every location ID.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function locationOptions(): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return [];
        }

        $options = [['label' => Craft::t('boomerang', 'Default location'), 'value' => '']];

        foreach ($plugin->restock->getLocationOptions() as $id => $name) {
            $options[] = ['label' => $name, 'value' => (string)$id];
        }

        return $options;
    }

    /**
     * @param array<int|string, mixed> $posted
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $posted): array
    {
        $lines = [];

        foreach ($posted as $lineItemId => $row) {
            $qty = (int)($row['qty'] ?? 0);

            if ($qty < 1) {
                continue;
            }

            $lines[(int)$lineItemId] = [
                'qty' => $qty,
                'reasonId' => $row['reasonId'] ?? null,
                'comment' => $row['comment'] ?? null,
            ];
        }

        return $lines;
    }

    private function findReturn(mixed $returnId): ReturnRequest
    {
        if ($returnId === null || $returnId === '') {
            throw new NotFoundHttpException('Return not found');
        }

        $return = Craft::$app->getElements()->getElementById((int)$returnId, ReturnRequest::class);

        if ($return === null) {
            throw new NotFoundHttpException('Return not found');
        }

        return $return;
    }
}
