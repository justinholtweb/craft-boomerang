<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\controllers;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use justinholtweb\boomerang\db\Table;
use justinholtweb\boomerang\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The store-credit ledger, for people who need to answer "where did my $40 go".
 */
class CreditController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_CREDIT);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('boomerang', 'Store credit needs Boomerang Pro.'));
        }

        return true;
    }

    /**
     * Every customer holding credit, largest balance first.
     */
    public function actionIndex(): Response
    {
        $rows = (new Query())
            ->from([Table::CREDITLOTS])
            ->select([
                'customerId',
                'currency',
                'balance' => 'SUM([[amountRemaining]])',
                'lots' => 'COUNT(*)',
                'nextExpiry' => 'MIN([[expiresAt]])',
            ])
            ->where(['>', 'amountRemaining', 0])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', \craft\helpers\Db::prepareDateForDb(new DateTime())]])
            ->groupBy(['customerId', 'currency'])
            ->orderBy(['balance' => SORT_DESC])
            ->limit(200)
            ->all();

        $users = [];

        if ($rows !== []) {
            foreach (User::find()->id(array_column($rows, 'customerId'))->status(null)->all() as $user) {
                $users[(int)$user->id] = $user;
            }
        }

        return $this->renderTemplate('boomerang/credit/_index', [
            'rows' => $rows,
            'users' => $users,
            'totals' => Plugin::getInstance()->analytics->credit(),
        ]);
    }

    public function actionDetail(int $customerId): Response
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUsers()->getUserById($customerId);

        if ($user === null) {
            throw new NotFoundHttpException('Customer not found');
        }

        return $this->renderTemplate('boomerang/credit/_detail', [
            'user' => $user,
            'balance' => $plugin->wallet->getBalance($customerId),
            'lots' => $plugin->wallet->getLots($customerId, includeEmpty: true),
            'entries' => $plugin->wallet->getEntries($customerId, limit: 200),
        ]);
    }

    /**
     * Give a customer credit by hand — a goodwill gesture, a migration from another system.
     */
    public function actionIssue(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $customerId = (int)$this->request->getRequiredBodyParam('customerId');
        $amount = (float)$this->request->getRequiredBodyParam('amount');
        $note = trim((string)$this->request->getBodyParam('note')) ?: null;
        $expiryDays = $this->request->getBodyParam('expiryDays');

        try {
            $plugin->wallet->issue($customerId, $amount, [
                'note' => $note,
                'expiryDays' => $expiryDays !== null && $expiryDays !== '' ? (int)$expiryDays : null,
                // Manual issues are deliberate acts, so each click is its own movement — but two
                // clicks a second apart are almost certainly one intent.
                'idempotencyKey' => sprintf('manual:%d:%s', $customerId, md5($customerId . '|' . $amount . '|' . $note . '|' . floor(time() / 5))),
            ]);
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect(UrlHelper::cpUrl('boomerang/credit/' . $customerId));
        }

        $this->setSuccessFlash(Craft::t('boomerang', 'Store credit issued.'));

        return $this->redirect(UrlHelper::cpUrl('boomerang/credit/' . $customerId));
    }

    /**
     * Take credit back.
     */
    public function actionRevoke(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $customerId = (int)$this->request->getRequiredBodyParam('customerId');
        $amount = (float)$this->request->getRequiredBodyParam('amount');
        $note = trim((string)$this->request->getBodyParam('note')) ?: null;

        $revoked = $plugin->wallet->revoke($customerId, $amount, sprintf(
            'revoke:%d:%s',
            $customerId,
            md5($customerId . '|' . $amount . '|' . $note . '|' . floor(time() / 5)),
        ), ['note' => $note]);

        if ($revoked < $amount) {
            // Credit is not a debt, and a wallet never goes negative. Saying so beats a silent
            // partial.
            $this->setFailFlash(Craft::t('boomerang', 'Only {revoked} could be revoked — the rest has already been spent.', [
                'revoked' => $revoked,
            ]));
        } else {
            $this->setSuccessFlash(Craft::t('boomerang', 'Store credit revoked.'));
        }

        return $this->redirect(UrlHelper::cpUrl('boomerang/credit/' . $customerId));
    }

    /**
     * Write off everything that has lapsed, now rather than on the next cron run.
     */
    public function actionExpire(): Response
    {
        $this->requirePostRequest();

        $result = Plugin::getInstance()->wallet->expire();

        $this->setSuccessFlash(Craft::t('boomerang', '{lots} lots expired, worth {amount}.', $result));

        return $this->redirect(UrlHelper::cpUrl('boomerang/credit'));
    }
}
