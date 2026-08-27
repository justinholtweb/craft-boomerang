<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handing a label to whoever is entitled to it.
 *
 * Two audiences with one route: a signed-in member of staff with the returns permission, and the
 * customer holding the RMA's portal token. Anyone else gets a 403 — a label carries the customer's
 * name and address, and a guessable URL that serves one is an address book with a download button.
 */
class LabelsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['download'];

    public function actionDownload(int $labelId): Response
    {
        $plugin = Plugin::getInstance();
        $label = $plugin->labels->getLabelById($labelId);

        if ($label === null || $label->returnId === null) {
            throw new NotFoundHttpException('Label not found');
        }

        $return = Craft::$app->getElements()->getElementById($label->returnId, ReturnRequest::class);

        if ($return === null) {
            throw new NotFoundHttpException('Return not found');
        }

        if (!$this->isEntitled($return)) {
            throw new ForbiddenHttpException('Not allowed');
        }

        $asset = $label->getAsset();

        if ($asset !== null) {
            return $this->response->sendStreamAsFile(
                $asset->getStream(),
                $asset->getFilename(),
                ['mimeType' => $asset->getMimeType(), 'inline' => true],
            );
        }

        if ($label->labelUrl !== null) {
            return $this->redirect($label->labelUrl);
        }

        throw new NotFoundHttpException('This label has no file.');
    }

    private function isEntitled(ReturnRequest $return): bool
    {
        if (Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return true;
        }

        // The customer's own account.
        $identity = Craft::$app->getUser()->getIdentity();

        if ($identity !== null && $return->customerId === $identity->id) {
            return true;
        }

        // Or the token from their status page. Compared in constant time: a token this route
        // hands a PDF to is worth guessing at.
        $token = (string)$this->request->getParam('rma', '');

        return $token !== ''
            && $return->portalToken !== null
            && hash_equals($return->portalToken, $token);
    }
}
