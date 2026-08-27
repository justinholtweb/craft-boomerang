<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use craft\commerce\models\LineItem;

/**
 * One line item's answer to "can this come back, and how many of it".
 */
class EligibleLine extends Model
{
    public ?int $lineItemId = null;
    public ?int $purchasableId = null;
    public string $sku = '';
    public string $description = '';
    public array $options = [];

    public int $qtyOrdered = 0;

    /** Already sitting on another RMA — open or resolved. */
    public int $qtyReturned = 0;

    /** What the customer may ask for now. Zero means the line is used up. */
    public int $qtyAvailable = 0;

    public float $unitPrice = 0.0;
    public float $unitTax = 0.0;
    public float $unitShipping = 0.0;

    public bool $isEligible = false;

    /** Why not, in words a customer can read. Empty when eligible. */
    public ?string $message = null;

    /** A machine-readable form of the same thing, for templates that want to branch. */
    public ?string $code = null;

    private ?LineItem $_lineItem = null;

    public function setLineItem(?LineItem $lineItem): void
    {
        $this->_lineItem = $lineItem;
    }

    public function getLineItem(): ?LineItem
    {
        return $this->_lineItem;
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
}
