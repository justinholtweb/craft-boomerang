# Boomerang — plan

Returns / RMA and a store-credit wallet for Craft Commerce 5.

Package `justinholtweb/craft-boomerang`, namespace `justinholtweb\boomerang`, handle `boomerang`.
**Lite (free) + Pro ($129).**

## Why it exists

There is no returns plugin for Craft Commerce. Core gives you `Payments::refundTransaction()`
against a payment and nothing else — no request, no approval, no receipt, no restock, no exchange,
no credit. Every Craft store either runs returns out of a shared inbox and a spreadsheet, or a
developer builds the same half of it again.

Boomerang is the whole loop: the customer asks, the merchant decides, the goods come back, the
stock goes back on the shelf, and the money goes back as cash, credit or replacement goods — with
the reasons recorded well enough to tell you which product keeps coming back and why.

## The invariants

Five, and everything else is arranged around them.

1. **`services\Eligibility::evaluate()` is the only place "can this be returned" is decided.**
   Portal, CP, console and the front-end API all read one `Eligibility` verdict carrying a
   per-line-item trace, so the reason a customer is refused is the reason the merchant sees.
2. **`services\Returns::transition()` is the only place a return changes state.** Guards, the
   event log, notifications, restock, refund, credit issue and exchange creation all hang off it,
   in one order, once. There is no second path that moves an RMA.
3. **`services\Wallet::post()` is the only place a credit ledger row is written**, and a balance is
   always `SUM(amountRemaining)` over unexpired lots — never a stored mutable number that can drift
   from its own history.
4. **`services\Restock::apply()` is the only place a return moves inventory**, and it is
   idempotent per return item (`restockedQty`), because a merchant clicking "Received" twice must
   not double the stock.
5. **`services\Refunds::issue()` is the only place money leaves.** It is the one call in the plugin
   that fails *loudly*: a failed refund leaves the RMA where it was.

## Data model

Eight tables, all hard-deleted, all in the database rather than project config — states and reasons
move between environments with `boomerang/states export|import`, the way Free Ride and Weight move
rules.

| Table | What it is |
| --- | --- |
| `boomerang_returns` | the RMA. Element sub-table. |
| `boomerang_returnitems` | one row per returned line item: qty, reason, condition, restock outcome. |
| `boomerang_states` | the state machine: name, handle, colour, kind, allowed transitions, flags. |
| `boomerang_reasons` | why it came back, plus what that reason implies for restock and analytics. |
| `boomerang_events` | the RMA's history — every transition, note, refund, label and restock. |
| `boomerang_labels` | return labels obtained from a provider. |
| `boomerang_creditlots` | issued store credit, with `amountRemaining` and an expiry. |
| `boomerang_creditentries` | signed movements against a lot. Unique on `idempotencyKey`. |

`orderId` is `SET NULL`, and the order's number, reference and email are copied onto the RMA when
it is made — an RMA has to stay answerable after the order is gone, which in several jurisdictions
is not optional.

## The state machine

States are configurable, not hard-coded, because "requested → approved → received → resolved" is
the *shape* rather than the vocabulary: a store selling mattresses has an inspection step and a
store selling t-shirts does not. Each state carries:

- a **kind** — `open`, `awaiting`, `received`, `resolved`, `rejected`, `cancelled` — which is what
  the rest of the plugin keys off. Code never asks "is this state called Received"; it asks
  `$state->isReceived()`.
- `transitions`: the state UIDs it may move to. Empty means anywhere, which is the escape hatch a
  merchant needs at 5pm on a Friday.
- flags: `isDefault`, `notifyCustomer`, `restockOnEnter`, `resolveOnEnter`.

Seeded on install with the six above so the plugin works before anybody configures anything.

## Resolutions

`refund` · `credit` · `exchange` · `replacement` · `none`. Chosen per RMA, executed on entry to a
resolving state, recorded as an event either way.

- **refund** — `Payments::refundTransaction()` across the order's refundable transactions,
  largest first. If it throws, the transition is abandoned.
- **credit** — a wallet lot, optionally uplifted by `creditBonusPercent` (the "110% back as store
  credit" lever that makes credit the resolution customers pick).
- **exchange** (Pro) — a new Commerce order for the customer carrying an `ExchangeCredit`
  adjustment worth the returned goods, so they pay only the difference. Where the credit exceeds
  the new order, the remainder becomes wallet credit rather than a negative total.
- **replacement** (Pro) — an exchange order for the same items at a zero balance.

## The wallet

A payment method, not a discount — the customer sees "Store credit" beside the card at checkout,
and the merchant sees a real transaction on the order.

`gateways\StoreCredit` supports authorize (allocate a hold), capture (retype the hold as a spend),
purchase (spend directly), refund (return it to the lot it came from) and **partial payment**, so
a $40 balance against a $95 order pays $40 and the card pays $55.

Allocation is FIFO by expiry, then by age, under `SELECT … FOR UPDATE` on the lots — a wallet is
the one place in a store where two concurrent requests spending the same money is a real bug and
not a hypothetical one. A refund returns the money to the exact lots it was drawn from, unless a
lot has expired since, in which case a fresh lot is issued rather than a customer being refunded
into money they cannot spend.

Expiry is enforced in the balance query as well as by `boomerang/credit/expire`, so an unrun cron
cannot let expired credit be spent.

## Editions

**Lite**: the portal, the state machine, reasons, eligibility, the event log, manual resolution,
notifications, CSV export.

**Pro**: the store-credit wallet and its gateway, exchange and replacement orders, restock into
Commerce 5 inventory locations, carrier return labels, and return analytics.

Pro configuration that survives a downgrade is **ignored, not obeyed** — `getEffective*()` gates
plus a CP warning naming what is being suppressed. Bouncer's fail-closed rule, applied to pricing.

## Restock (Pro)

`Inventory::updateInventoryLevel()` with `InventoryUpdateQuantityType::ADJUST`, which inserts a
signed transaction row — the correct primitive for goods physically arriving back, as opposed to
`InventoryRestockMovement`, which moves `committed → available` and is for an order that was never
shipped.

The destination transaction type comes from the item's **condition**, not from a setting:
resellable goods go back to `available`, damaged goods to `damaged`, anything needing a look to
`qualityControl`. That is the difference between a stock figure a merchant trusts and one they
override every week.

## Labels (Pro)

`labels\LabelProviderInterface` with two implementations: `Manual` (paste a tracking number,
upload the label) and `ShipStation` (v2 REST, `is_return_label`). The interface is the product —
one carrier integration is a hostage to one vendor's roadmap.

## Analytics (Pro)

Reason mix, return rate by product and variant, value returned, time to resolution, restock
recovery rate, credit issued against credit redeemed. Hand-built SVG, no chart library, CSV export
of everything on screen.

## Front end

`_portal/` templates a merchant can use as-is or copy and rewrite: lookup (order number + email
for guests, nothing for a logged-in customer), the request form, the status page behind a
32-character token, and the label download. Rate-limited, constant-time token comparison.

## Build order

1. Skeleton, settings, editions, install migration
2. States and reasons — models, records, services, CP
3. The `ReturnRequest` element, its query, items and events
4. Eligibility and the transition service
5. The portal
6. The wallet and the gateway; refunds
7. Restock, exchanges, the adjuster
8. Labels
9. Analytics
10. Console commands, Twig variable, notifications
11. `tests/integration/checks.php`, wired into the shared harness
