<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\mail\Message;
use craft\web\View;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\models\State;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\queue\jobs\SendNotification;

/**
 * Telling people what happened.
 *
 * Every send is wrapped: a mail server that is down must never be able to stop a state change that
 * has already moved money. A failure is logged on the RMA's history, where a merchant will find it
 * when the customer says they never heard anything, and nowhere else.
 */
class Notifications extends Component
{
    /**
     * Tell the desk a return has come in.
     */
    public function returnCreated(ReturnRequest $return): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyStaff) {
            return;
        }

        $recipients = $settings->getStaffRecipients();

        if ($recipients === []) {
            return;
        }

        $this->dispatch($return, $recipients, null, 'staff-new-return');
    }

    /**
     * Tell the customer their return moved.
     */
    public function stateChanged(ReturnRequest $return, State $state): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyCustomer || $return->email === '') {
            return;
        }

        $this->dispatch($return, [$return->email], $state, 'customer-state-changed');
    }

    /**
     * Warn a customer their store credit is about to lapse.
     *
     * @param float $amount The amount expiring.
     */
    public function creditExpiring(int $customerId, float $amount, \DateTime $expiresAt): bool
    {
        $user = Craft::$app->getUsers()->getUserById($customerId);

        if ($user === null || $user->email === '') {
            return false;
        }

        $subject = Craft::t('boomerang', 'Your store credit expires on {date}', [
            'date' => Craft::$app->getFormatter()->asDate($expiresAt, 'long'),
        ]);

        $body = $this->render('boomerang/_emails/credit-expiring', [
            'user' => $user,
            'amount' => $amount,
            'expiresAt' => $expiresAt,
        ]);

        return $this->send([$user->email], $subject, $body);
    }

    /**
     * Build and send, or queue.
     *
     * @param string[] $recipients
     */
    private function dispatch(ReturnRequest $return, array $recipients, ?State $state, string $template): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->queueNotifications && $return->id !== null) {
            Craft::$app->getQueue()->push(new SendNotification([
                'returnId' => $return->id,
                'stateId' => $state?->id,
                'recipients' => $recipients,
                'template' => $template,
            ]));

            return;
        }

        $this->sendNow($return, $recipients, $state, $template);
    }

    /**
     * The one place a return notification is actually composed.
     *
     * Called directly when notifications are sent in-request, and by the queue job otherwise, so
     * that what a test suite exercises is what production sends.
     *
     * @param string[] $recipients
     */
    public function sendNow(ReturnRequest $return, array $recipients, ?State $state, string $template): bool
    {
        $subject = $this->subjectFor($return, $state, $template);
        $body = $this->bodyFor($return, $state, $template);

        $sent = $this->send($recipients, $subject, $body);

        Plugin::getInstance()->returns->addEvent(
            $return,
            $sent ? EventType::NotificationSent : EventType::Failed,
            $sent ? null : Craft::t('boomerang', 'The notification could not be sent.'),
            ['to' => $recipients, 'template' => $template, 'state' => $state?->handle],
        );

        return $sent;
    }

    private function subjectFor(ReturnRequest $return, ?State $state, string $template): string
    {
        // A state's own subject wins, so a merchant can write "We've got your parcel" for the one
        // transition customers care about without templating the other six.
        if ($state?->emailSubject) {
            return $this->renderObject($state->emailSubject, $return);
        }

        if ($template === 'staff-new-return') {
            return Craft::t('boomerang', 'New return {reference} from {email}', [
                'reference' => $return->reference,
                'email' => $return->email,
            ]);
        }

        return Craft::t('boomerang', 'Your return {reference} is now {state}', [
            'reference' => $return->reference,
            'state' => $state?->name ?? '',
        ]);
    }

    private function bodyFor(ReturnRequest $return, ?State $state, string $template): string
    {
        if ($state?->emailBody) {
            return $this->renderObject($state->emailBody, $return);
        }

        return $this->render('boomerang/_emails/' . $template, [
            'return' => $return,
            'state' => $state,
            'items' => $return->getItems(),
        ]);
    }

    /**
     * @param string[] $recipients
     */
    private function send(array $recipients, string $subject, string $body): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $message = new Message();
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $message->setTo($recipients);

            $from = $settings->getFromEmail();

            if ($from !== null) {
                $message->setFrom([$from => $settings->getFromName() ?? $from]);
            }

            return Craft::$app->getMailer()->send($message);
        } catch (\Throwable $e) {
            // Never fatal. A mail server being down must not undo a refund.
            Craft::error('Boomerang could not send a notification: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * Render one of the plugin's own email templates, letting the site override it.
     *
     * A site template of the same path wins, which is how a merchant restyles the emails without
     * touching the plugin.
     */
    private function render(string $template, array $variables): string
    {
        try {
            // Site mode, against the `boomerang` template root the plugin registers: Craft checks
            // the site's own templates folder first, so a merchant's copy of the same path wins
            // and no setting has to be flipped for it to.
            return Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        } catch (\Throwable $e) {
            Craft::error('Boomerang could not render ' . $template . ': ' . $e->getMessage(), __METHOD__);

            return '';
        }
    }

    /**
     * Render a merchant-written snippet against the RMA.
     *
     * `renderObjectTemplate()` calls its subject `object`, which is not what anybody types first,
     * so the return is passed in as `return` as well.
     */
    private function renderObject(string $template, ReturnRequest $return): string
    {
        try {
            return Craft::$app->getView()->renderObjectTemplate($template, $return, [
                'return' => $return,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Boomerang could not render an email template: ' . $e->getMessage(), __METHOD__);

            return '';
        }
    }
}
