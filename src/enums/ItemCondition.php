<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\enums;

use Craft;
use craft\commerce\enums\InventoryTransactionType;

/**
 * What state the goods came back in — and therefore which shelf they go back on.
 *
 * This is the whole reason Boomerang restocks into a *transaction type* rather than just adding to
 * stock: a returned t-shirt someone tried on and a returned t-shirt with a hole in it are not the
 * same number, and a merchant who has to correct the stock figure every week stops trusting it.
 */
enum ItemCondition: string
{
    /** Sellable again as-is. */
    case Resellable = 'resellable';

    /** Opened, needs checking before it goes back on sale. */
    case Inspect = 'inspect';

    /** Damaged. On hand, but not sellable. */
    case Damaged = 'damaged';

    /** Never arrived, or arrived as something else. Nothing to restock. */
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Resellable => Craft::t('boomerang', 'Resellable'),
            self::Inspect => Craft::t('boomerang', 'Needs inspection'),
            self::Damaged => Craft::t('boomerang', 'Damaged'),
            self::Missing => Craft::t('boomerang', 'Missing'),
        };
    }

    /**
     * The Commerce inventory transaction type goods in this condition are added to.
     *
     * `null` means nothing is added at all.
     */
    public function inventoryTransactionType(): ?InventoryTransactionType
    {
        return match ($this) {
            self::Resellable => InventoryTransactionType::AVAILABLE,
            self::Inspect => InventoryTransactionType::QUALITY_CONTROL,
            self::Damaged => InventoryTransactionType::DAMAGED,
            self::Missing => null,
        };
    }

    public function isRestockable(): bool
    {
        return $this->inventoryTransactionType() !== null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
