<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\services;

use Craft;
use craft\base\Component;
use craft\commerce\collections\UpdateInventoryLevelCollection;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\inventory\UpdateInventoryLevel;
use craft\commerce\models\InventoryLocation;
use craft\commerce\Plugin as Commerce;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\enums\EventType;
use justinholtweb\boomerang\models\ReturnItem;
use justinholtweb\boomerang\Plugin;
use RuntimeException;

/**
 * Putting the goods back on a shelf.
 *
 * **The invariant: `apply()` is the only place a return moves inventory**, and it is idempotent
 * per item — `qtyRestocked` is written in the same breath as the movement, so a merchant clicking
 * "Received" twice does not double the stock.
 *
 * ## Why an adjustment, not a restock movement
 *
 * Commerce ships `InventoryRestockMovement`, which moves `committed → available`. That is the
 * right primitive for an order that was cancelled before it shipped: the stock never left. Goods
 * physically coming back from a customer are different — the `committed` quantity was consumed by
 * fulfilment long ago — so the correct operation is an **adjustment**, which inserts a signed
 * transaction row against a type. Using the restock movement here would drive `committed`
 * negative and quietly corrupt every subsequent availability calculation.
 *
 * ## The condition picks the shelf
 *
 * Resellable goods go to `available`, damaged goods to `damaged`, anything that needs a look to
 * `qualityControl`. That is the difference between a stock figure a merchant trusts and one they
 * override every week — and it is why Boomerang asks about condition at receipt rather than
 * offering a single "restock?" checkbox.
 */
class Restock extends Component
{
    /**
     * Put an RMA's received goods back.
     *
     * @return array{items: int, quantity: int} What actually moved.
     */
    public function apply(ReturnRequest $return, ?int $inventoryLocationId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->getEffectiveRestockEnabled()) {
            return ['items' => 0, 'quantity' => 0];
        }

        $location = $this->resolveLocation($inventoryLocationId ?? $return->inventoryLocationId);

        if ($location === null) {
            throw new RuntimeException(Craft::t('boomerang', 'This store has no inventory location to restock into.'));
        }

        $moved = 0;
        $items = 0;

        foreach ($return->getItems() as $item) {
            $qty = $this->restockItem($return, $item, $location);

            if ($qty > 0) {
                $moved += $qty;
                $items++;
            }
        }

        if ($moved > 0) {
            $return->inventoryLocationId = (int)$location->id;

            Plugin::getInstance()->returns->addEvent($return, EventType::Restocked, null, [
                'items' => $items,
                'quantity' => $moved,
                'location' => $location->name,
                'locationId' => $location->id,
            ]);
        }

        return ['items' => $items, 'quantity' => $moved];
    }

    /**
     * Restock one item, and record that it happened.
     *
     * @return int The quantity moved. Zero is the normal answer for an item that was already
     *             restocked, arrived damaged beyond use, or was never tracked.
     */
    public function restockItem(ReturnRequest $return, ReturnItem $item, InventoryLocation $location): int
    {
        $qty = $item->getRestockableQty();

        if ($qty < 1) {
            return 0;
        }

        $type = $item->getCondition()->inventoryTransactionType();

        if ($type === null) {
            return 0;
        }

        $purchasable = $item->getPurchasable();

        if ($purchasable === null) {
            // The product has been deleted since the order. There is nothing to put stock back
            // onto, and saying so beats failing the whole transition.
            return 0;
        }

        if (!$purchasable->inventoryTracked || $purchasable->inventoryItemId === null) {
            return 0;
        }

        $update = new UpdateInventoryLevel([
            'inventoryItemId' => (int)$purchasable->inventoryItemId,
            'inventoryLocationId' => (int)$location->id,
            'type' => $type->value,
            'updateAction' => InventoryUpdateQuantityType::ADJUST,
            'quantity' => $qty,
            'note' => Craft::t('boomerang', 'Return {reference}', ['reference' => $return->reference]),
        ]);

        Commerce::getInstance()->getInventory()->executeUpdateInventoryLevels(
            UpdateInventoryLevelCollection::make([$update]),
        );

        // Written immediately, in the same call: the guarantee that a retried transition cannot
        // move the same units twice is this counter, not the caller's care.
        $item->qtyRestocked += $qty;
        Plugin::getInstance()->returns->saveItem($item);

        return $qty;
    }

    /**
     * Where returned goods land.
     *
     * The RMA's own location, then the configured default, then the store's first — in that order,
     * so a merchant with one warehouse never has to configure anything and a merchant with five
     * can decide per return.
     */
    public function resolveLocation(?int $inventoryLocationId = null): ?InventoryLocation
    {
        $locations = Commerce::getInstance()->getInventoryLocations();

        foreach ([$inventoryLocationId, Plugin::getInstance()->getSettings()->defaultInventoryLocationId] as $id) {
            if ($id === null) {
                continue;
            }

            $location = $locations->getInventoryLocationById((int)$id);

            if ($location !== null) {
                return $location;
            }
        }

        return $locations->getAllInventoryLocations()->first();
    }

    /**
     * @return array<int, string> id => name, for a dropdown.
     */
    public function getLocationOptions(): array
    {
        $options = [];

        foreach (Commerce::getInstance()->getInventoryLocations()->getAllInventoryLocations() as $location) {
            $options[(int)$location->id] = $location->name;
        }

        return $options;
    }
}
