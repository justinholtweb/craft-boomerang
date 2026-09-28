<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The state machine's own screens.
 */
class StatesController extends Controller
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
        return $this->renderTemplate('boomerang/states/_index', [
            'states' => Plugin::getInstance()->states->getAllStates(),
        ]);
    }

    public function actionEdit(?int $stateId = null, ?State $state = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($state === null) {
            $state = $stateId !== null ? $plugin->states->getStateById($stateId) : new State();

            if ($state === null) {
                throw new NotFoundHttpException('State not found');
            }
        }

        return $this->renderTemplate('boomerang/states/_edit', [
            'state' => $state,
            'kinds' => StateKind::options(),
            'allStates' => array_values(array_filter(
                $plugin->states->getAllStates(),
                fn(State $other) => $other->uid !== $state->uid,
            )),
            'isNew' => $state->id === null,
            'returnCount' => $state->id !== null ? $plugin->states->getReturnCount((int)$state->id) : 0,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = $this->request;

        $stateId = $request->getBodyParam('stateId');
        $state = $stateId ? $plugin->states->getStateById((int)$stateId) : new State();

        if ($state === null) {
            throw new NotFoundHttpException('State not found');
        }

        $state->name = trim((string)$request->getBodyParam('name'));
        $state->handle = trim((string)$request->getBodyParam('handle'));
        $state->kind = (string)$request->getBodyParam('kind', $state->kind);
        // Craft's colour field posts hex without the leading `#`.
        $color = trim((string)$request->getBodyParam('color'));
        $state->color = $color !== '' ? ltrim($color, '#') : null;
        $state->description = trim((string)$request->getBodyParam('description')) ?: null;
        $state->isDefault = (bool)$request->getBodyParam('isDefault');
        $state->notifyCustomer = (bool)$request->getBodyParam('notifyCustomer');
        $state->restockOnEnter = (bool)$request->getBodyParam('restockOnEnter');
        $state->resolveOnEnter = (bool)$request->getBodyParam('resolveOnEnter');
        $state->offerLabel = (bool)$request->getBodyParam('offerLabel');
        // The subject and body are Twig, run on every state change with the whole of Craft in
        // reach, so only an admin may change them — the same rule Craft applies to its own
        // Twig-bearing settings. Anyone else with this permission can edit the rest of the state,
        // and their save keeps the templates as they were.
        if (Craft::$app->getUser()->getIsAdmin()) {
            $state->emailSubject = trim((string)$request->getBodyParam('emailSubject')) ?: null;
            $state->emailBody = trim((string)$request->getBodyParam('emailBody')) ?: null;
        }
        $state->transitions = array_values(array_filter((array)$request->getBodyParam('transitions', [])));

        if (!$plugin->states->saveState($state)) {
            $this->setFailFlash(Craft::t('boomerang', 'Couldn’t save the state.'));
            Craft::$app->getUrlManager()->setRouteParams(['state' => $state]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'State saved.'));

        return $this->redirectToPostedUrl($state);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $stateId = (int)$this->request->getRequiredBodyParam('id');
        $moveTo = $this->request->getBodyParam('moveToStateId');

        $count = $plugin->states->getReturnCount($stateId);

        if ($count > 0 && !$moveTo) {
            // Deleting out from under live returns would leave them stateless — and a stateless
            // return counts as open forever, which is a queue nobody can clear.
            return $this->asFailure(Craft::t('boomerang', 'There are {count} returns in this state. Choose one to move them to first.', [
                'count' => $count,
            ]));
        }

        $plugin->states->deleteStateById($stateId, $moveTo ? (int)$moveTo : null);

        return $this->asSuccess();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->states->reorderStates(array_map('intval', $ids));

        return $this->asSuccess();
    }
}
