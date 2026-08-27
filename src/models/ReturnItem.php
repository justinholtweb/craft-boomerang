<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use Craft;
use craft\base\Model;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use DateTime;
use justinholtweb\boomerang\enums\ItemCondition;
use justinholtweb\boomerang\Plugin;

/**
 * One line item coming back, in whole or in part.
 *
 * ## Why the price is copied rather than read
 *
 * `unitPrice` and `unitTax` are a snapshot taken when the RMA is made, not a lookup through
 * `lineItemId`. A line item's sale price is recalculated whenever its order is touched, and an
 * order *is* touched — by a merchant fixing an address, by a plugin, by Commerce itself on
 * upgrade. A refund worked out six weeks later from a live line item is a refund for a number
 * nobody agreed to. The snapshot is also why `lineItemId` and `purchasableId` can both be
 * `SET NULL` without the RMA becoming unanswerable.
 */
class ReturnItem extends Model
{
    public ?int $id = null;
    public ?int $returnId = null;
    public ?int $lineItemId = null;
    public ?int $purchasableId = null;

    public string $sku = '';
    public string $description = '';

    /** The line item's options at the time, so "Large / Blue" survives the line item. */
    public array $options = [];

    public int $qtyRequested = 1;
    public int $qtyApproved = 0;
    public int $qtyReceived = 0;
    public int $qtyRestocked = 0;

    public ?int $reasonId = null;

    /** Copied at creation, so a deleted reason does not erase why this came back. */
    public ?string $reasonName = null;

    public ?string $customerComment = null;
    public ?string $staffNote = null;

    public string $condition = ItemCondition::Inspect->value;

    /** Snapshot: what one of these was actually charged at, before tax. */
    public float $unitPrice = 0.0;

    /** Snapshot: the tax attributable to one of them. */
    public float $unitTax = 0.0;

    /** Snapshot: the shipping attributable to one of them. Refunded only if the merchant says so. */
    public float $unitShipping = 0.0;

    /** Asset IDs the customer uploaded as evidence. */
    public array $photoIds = [];

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?LineItem $_lineItem = null;

    public function getCondition(): ItemCondition
    {
        return ItemCondition::tryFrom($this->condition) ?? ItemCondition::Inspect;
    }

    public function getReason(): ?Reason
    {
        return $this->reasonId !== null
            ? Plugin::getInstance()->reasons->getReasonById($this->reasonId)
            : null;
    }

    /**
     * The reason's name, falling back to the copy taken when the RMA was made.
     */
    public function getReasonLabel(): string
    {
        return $this->getReason()?->name
            ?? $this->reasonName
            ?? Craft::t('boomerang', 'Not given');
    }

    public function getLineItem(): ?LineItem
    {
        if ($this->_lineItem !== null) {
            return $this->_lineItem;
        }

        if ($this->lineItemId === null) {
            return null;
        }

        return $this->_lineItem = Commerce::getInstance()->getLineItems()->getLineItemById($this->lineItemId);
    }

    public function getPurchasable(): ?PurchasableInterface
    {
        if ($this->purchasableId === null) {
            return null;
        }

        return Commerce::getInstance()->getPurchasables()->getPurchasableById($this->purchasableId);
    }

    /**
     * @return Asset[]
     */
    public function getPhotos(): array
    {
        if ($this->photoIds === []) {
            return [];
        }

        return Asset::find()->id($this->photoIds)->all();
    }

    /**
     * The quantity the resolution is worked out against.
     *
     * Approved quantity once somebody has approved one, requested quantity before that. A merchant
     * who approves 1 of the 3 a customer asked to send back is refunding 1.
     */
    public function getResolvableQty(): int
    {
        return $this->qtyApproved > 0 ? $this->qtyApproved : $this->qtyRequested;
    }

    /**
     * What this item is worth back, at the snapshot prices.
     */
    public function getRefundAmount(bool $includeTax = true, bool $includeShipping = false): float
    {
        $unit = $this->unitPrice
            + ($includeTax ? $this->unitTax : 0.0)
            + ($includeShipping ? $this->unitShipping : 0.0);

        return round($unit * $this->getResolvableQty(), 4);
    }

    /**
     * How many of these are still waiting to go back on a shelf.
     */
    public function getRestockableQty(): int
    {
        if (!$this->getCondition()->isRestockable()) {
            return 0;
        }

        return max(0, $this->qtyReceived - $this->qtyRestocked);
    }

    public function getOptionsLabel(): string
    {
        if ($this->options === []) {
            return '';
        }

        $parts = [];

        foreach ($this->options as $key => $value) {
            $parts[] = is_scalar($value) ? sprintf('%s: %s', $key, $value) : $key;
        }

        return implode(', ', $parts);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['qtyRequested'], 'required'];
        $rules[] = [['qtyRequested'], 'integer', 'min' => 1];
        $rules[] = [['qtyApproved', 'qtyReceived', 'qtyRestocked'], 'integer', 'min' => 0];
        $rules[] = [['unitPrice', 'unitTax', 'unitShipping'], 'number'];
        $rules[] = [['condition'], 'in', 'range' => array_column(ItemCondition::cases(), 'value')];
        $rules[] = [['sku', 'description'], 'string', 'max' => 255];
        $rules[] = [['options', 'photoIds'], 'safe'];

        // Approving more than was asked for is a data-entry slip that turns into an over-refund.
        $rules[] = [
            ['qtyApproved'],
            function(string $attribute) {
                if ($this->qtyApproved > $this->qtyRequested) {
                    $this->addError($attribute, Craft::t('boomerang', 'Cannot approve more than was requested.'));
                }
            },
        ];

        $rules[] = [
            ['qtyRestocked'],
            function(string $attribute) {
                if ($this->qtyRestocked > $this->qtyReceived) {
                    $this->addError($attribute, Craft::t('boomerang', 'Cannot restock more than was received.'));
                }
            },
        ];

        return $rules;
    }
}
