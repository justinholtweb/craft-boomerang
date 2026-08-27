<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;

/**
 * Send a return notification out of band.
 *
 * The job holds IDs rather than models, so a queue backlog cannot deliver an email describing a
 * state the RMA left an hour ago.
 */
class SendNotification extends BaseJob
{
    public int $returnId;
    public ?int $stateId = null;

    /** @var string[] */
    public array $recipients = [];

    public string $template = 'customer-state-changed';

    public function execute($queue): void
    {
        $return = Craft::$app->getElements()->getElementById($this->returnId, ReturnRequest::class);

        if ($return === null || $this->recipients === []) {
            return;
        }

        $state = $this->stateId !== null
            ? Plugin::getInstance()->states->getStateById($this->stateId)
            : null;

        Plugin::getInstance()->notifications->sendNow($return, $this->recipients, $state, $this->template);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('boomerang', 'Sending a return notification');
    }
}
