<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\records\ReasonRecord;

/**
 * Why it came back.
 *
 * A reason is not just a label on a form. It carries what the merchant wants to do about it:
 * whether the customer has to explain themselves, whether a photo is required before anybody
 * ships anything, what condition the goods are assumed to arrive in, and — the one that pays for
 * the plugin — whether the return is the store's fault.
 *
 * `isFault` is what separates "we sent the wrong thing" from "they ordered two sizes on purpose".
 * A return rate that mixes the two tells you nothing.
 */
class Reason extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public int $sortOrder = 0;
    public bool $enabled = true;

    /** Shown to customers on the portal. A merchant-only reason is for the CP. */
    public bool $customerSelectable = true;

    public bool $requiresComment = false;
    public bool $requiresPhoto = false;

    /** The store got it wrong. Drives who pays for return shipping, and the fault-rate report. */
    public bool $isFault = false;

    /** Skip the approval step for this reason and go straight to approved. */
    public bool $autoApprove = false;

    /** The store pays for the return label when this is the reason. */
    public bool $freeReturnShipping = false;

    /** What condition goods returned for this reason are assumed to be in, until inspected. */
    public string $defaultCondition = ItemCondition::Inspect->value;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getDefaultCondition(): ItemCondition
    {
        return ItemCondition::tryFrom($this->defaultCondition) ?? ItemCondition::Inspect;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('boomerang/reasons/' . $this->id);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid']];
        $rules[] = [['handle'], UniqueValidator::class, 'targetClass' => ReasonRecord::class, 'targetAttribute' => 'handle'];
        $rules[] = [['defaultCondition'], 'in', 'range' => array_column(ItemCondition::cases(), 'value')];
        $rules[] = [['sortOrder'], 'integer'];
        $rules[] = [
            ['enabled', 'customerSelectable', 'requiresComment', 'requiresPhoto', 'isFault', 'autoApprove', 'freeReturnShipping'],
            'boolean',
        ];
        $rules[] = [['description'], 'string'];

        return $rules;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
