<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\boomerang\enums\StateKind;
use justinholtweb\boomerang\Plugin;
use justinholtweb\boomerang\records\StateRecord;

/**
 * One state in the RMA state machine.
 *
 * The merchant owns the vocabulary; the plugin owns the meaning. {@see $kind} is what the rest of
 * Boomerang keys off — nothing anywhere asks whether a state is *called* "Received".
 */
class State extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $kind = StateKind::Requested->value;
    public ?string $color = null;
    public ?string $description = null;
    public int $sortOrder = 0;
    public bool $isDefault = false;

    /** Send the customer the state's notification when an RMA arrives here. */
    public bool $notifyCustomer = false;

    /** Put the goods back into inventory on arrival here. Pro; ignored on Lite. */
    public bool $restockOnEnter = false;

    /** Carry out the RMA's resolution on arrival here. */
    public bool $resolveOnEnter = false;

    /** Let the customer request a return label while an RMA sits here. */
    public bool $offerLabel = false;

    public ?string $emailSubject = null;
    public ?string $emailBody = null;

    /**
     * UIDs of the states an RMA here may move to. Empty means "anywhere" — the escape hatch a
     * merchant needs at 5pm on a Friday, and the default, because a state machine nobody can
     * override is one people work around in the database.
     *
     * @var string[]
     */
    public array $transitions = [];

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getKind(): StateKind
    {
        return StateKind::tryFrom($this->kind) ?? StateKind::Requested;
    }

    public function getColor(): string
    {
        return $this->color ?: $this->getKind()->defaultColor();
    }

    public function isRequested(): bool
    {
        return $this->getKind() === StateKind::Requested;
    }

    public function isApproved(): bool
    {
        return $this->getKind() === StateKind::Approved;
    }

    public function isReceived(): bool
    {
        return $this->getKind() === StateKind::Received;
    }

    public function isResolved(): bool
    {
        return $this->getKind() === StateKind::Resolved;
    }

    public function isRejected(): bool
    {
        return $this->getKind() === StateKind::Rejected;
    }

    public function isCancelled(): bool
    {
        return $this->getKind() === StateKind::Cancelled;
    }

    public function isClosed(): bool
    {
        return $this->getKind()->isClosed();
    }

    /**
     * Whether the customer may still call it off themselves from the portal.
     */
    public function isCustomerCancellable(): bool
    {
        return $this->getKind()->isCustomerCancellable();
    }

    /**
     * Whether an RMA here may move to $state.
     *
     * A state can always move to itself — re-saving an RMA without touching its state is not a
     * transition and must never be refused.
     */
    public function allowsTransitionTo(self $state): bool
    {
        if ($state->uid === $this->uid) {
            return true;
        }

        if ($this->transitions === []) {
            return true;
        }

        return in_array($state->uid, $this->transitions, true);
    }

    /**
     * The states an RMA here may move to, in sort order.
     *
     * @return self[]
     */
    public function getAvailableTransitions(): array
    {
        return array_values(array_filter(
            Plugin::getInstance()->states->getAllStates(),
            fn(self $state) => $state->uid !== $this->uid && $this->allowsTransitionTo($state),
        ));
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('boomerang/states/' . $this->id);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'handle', 'kind'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid']];
        $rules[] = [['handle'], UniqueValidator::class, 'targetClass' => StateRecord::class, 'targetAttribute' => 'handle'];
        $rules[] = [['kind'], 'in', 'range' => array_column(StateKind::cases(), 'value')];
        $rules[] = [['sortOrder'], 'integer'];
        $rules[] = [['isDefault', 'notifyCustomer', 'restockOnEnter', 'resolveOnEnter', 'offerLabel'], 'boolean'];
        $rules[] = [['description', 'emailSubject', 'emailBody', 'color'], 'string'];
        $rules[] = [['transitions'], 'safe'];

        return $rules;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
