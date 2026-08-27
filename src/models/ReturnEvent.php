<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use justinholtweb\boomerang\enums\EventType;

/**
 * One thing that happened to an RMA.
 *
 * Append-only. Nothing in the plugin updates or deletes an event, because the value of a history
 * is entirely in nobody being able to tidy it.
 */
class ReturnEvent extends Model
{
    public ?int $id = null;
    public ?int $returnId = null;
    public string $type = EventType::Note->value;

    /** Who did it. Null means the customer, the queue, or a console command. */
    public ?int $userId = null;

    /** Set when the customer did it from the portal, so "who" is never ambiguous. */
    public bool $byCustomer = false;

    public ?string $message = null;

    /** Structured detail — amounts, state UIDs, transaction references. */
    public array $data = [];

    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public function getType(): EventType
    {
        return EventType::tryFrom($this->type) ?? EventType::Note;
    }

    public function getUser(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    /**
     * Who to credit this to, in words.
     */
    public function getActorLabel(): string
    {
        if ($this->byCustomer) {
            return Craft::t('boomerang', 'Customer');
        }

        $user = $this->getUser();

        if ($user !== null) {
            return (string)$user->getUiLabel();
        }

        return Craft::t('boomerang', 'System');
    }

    public function isCustomerVisible(): bool
    {
        return $this->getType()->isCustomerVisible();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['type'], 'required'];
        $rules[] = [['type'], 'in', 'range' => array_column(EventType::cases(), 'value')];
        $rules[] = [['message'], 'string'];
        $rules[] = [['data'], 'safe'];

        return $rules;
    }
}
