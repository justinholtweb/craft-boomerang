---
title: Restocking and exchanges
slug: restocking
order: 60
summary: Which shelf returned goods go back to, why it is an adjustment rather than a restock movement, and how exchange orders carry their credit.
---

**Pro.** Both of these run on arrival at a state that has the relevant switch turned on — see
[The state machine](states#what-a-state-can-do).

## The right shelf

Returned goods do not all go back to the same place. Each return item carries a **condition**, which
decides the inventory transaction type it is adjusted into:

| Condition | Goes to |
| --- | --- |
| Resellable | available |
| Damaged | damaged |
| Inspect | quality control |

The condition is defaulted from the [reason](states#reasons) — goods that "arrived damaged" default
to damaged, goods that are "faulty" default to quality control — and the desk can change it per item
when the box is actually open.

## It is an adjustment, not a restock movement

Commerce ships `InventoryRestockMovement`, and it is the wrong tool here. That model moves
`committed → available`, for an order that was never shipped.

Goods coming back from a customer had their committed quantity consumed by fulfilment long ago, so
using it would drive `committed` **negative**. Boomerang uses `UpdateInventoryLevel` with
`InventoryUpdateQuantityType::ADJUST` instead, and picks the transaction type from the item's
condition.

## It is idempotent

`services\Restock::apply()` is the only place a return moves inventory, and it is idempotent per
item through `qtyRestocked`. Re-entering a state that restocks, retrying a failed transition, or
running the same transition twice from two browser tabs does not restock twice.

## Exchange and replacement orders

Two resolutions create a new order:

- **Exchange** — a new order for different goods, carrying a credit for what came back, so the
  customer pays only the difference.
- **Replacement** — the same goods again, at a zero balance.

The credit is applied to the new order, so the customer's payment step shows the difference rather
than the full price with a promise of a refund later.

When the exchange order is completed for less than the credit it carried, the remainder is settled
back — into the wallet if the store has one, and otherwise as a refund.

## Inventory locations

A return is restocked into one inventory location, chosen on the RMA. Stores with a single location
never see the field; stores with several get to say which warehouse the goods physically arrived at,
which is not always the one they shipped from.
