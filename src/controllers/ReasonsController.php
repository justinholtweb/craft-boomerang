<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\models\Reason;
use justinholtweb\boomerang\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Return reasons.
 */
class ReasonsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_CONFIGURE);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('boomerang/reasons/_index', [
            'reasons' => Plugin::getInstance()->reasons->getAllReasons(),
        ]);
    }

    public function actionEdit(?int $reasonId = null, ?Reason $reason = null): Response
    {
        if ($reason === null) {
            $reason = $reasonId !== null
                ? Plugin::getInstance()->reasons->getReasonById($reasonId)
                : new Reason();

            if ($reason === null) {
                throw new NotFoundHttpException('Reason not found');
            }
        }

        return $this->renderTemplate('boomerang/reasons/_edit', [
            'reason' => $reason,
            'conditions' => ItemCondition::options(),
            'isNew' => $reason->id === null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = $this->request;

        $reasonId = $request->getBodyParam('reasonId');
        $reason = $reasonId ? $plugin->reasons->getReasonById((int)$reasonId) : new Reason();

        if ($reason === null) {
            throw new NotFoundHttpException('Reason not found');
        }

        $reason->name = trim((string)$request->getBodyParam('name'));
        $reason->handle = trim((string)$request->getBodyParam('handle'));
        $reason->description = trim((string)$request->getBodyParam('description')) ?: null;
        $reason->enabled = (bool)$request->getBodyParam('enabled', true);
        $reason->customerSelectable = (bool)$request->getBodyParam('customerSelectable');
        $reason->requiresComment = (bool)$request->getBodyParam('requiresComment');
        $reason->requiresPhoto = (bool)$request->getBodyParam('requiresPhoto');
        $reason->isFault = (bool)$request->getBodyParam('isFault');
        $reason->autoApprove = (bool)$request->getBodyParam('autoApprove');
        $reason->freeReturnShipping = (bool)$request->getBodyParam('freeReturnShipping');
        $reason->defaultCondition = (string)$request->getBodyParam('defaultCondition', $reason->defaultCondition);

        if (!$plugin->reasons->saveReason($reason)) {
            $this->setFailFlash(Craft::t('boomerang', 'Couldn’t save the reason.'));
            Craft::$app->getUrlManager()->setRouteParams(['reason' => $reason]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Reason saved.'));

        return $this->redirectToPostedUrl($reason);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->reasons->deleteReasonById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->reasons->reorderReasons(array_map('intval', $ids));

        return $this->asSuccess();
    }
}
