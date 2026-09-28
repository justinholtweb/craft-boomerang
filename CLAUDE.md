# Boomerang — Craft CMS 5 Plugin

## Project Overview

Returns, RMAs and a store-credit wallet for Craft Commerce 5. Distributed as
`justinholtweb/craft-boomerang`. **Lite (free) + Pro ($129).**

The package, handle and namespace are all `boomerang`. **There are two clones of this repo:**
`~/Sites/craft-boomerang` (where releases are cut and tagged) and `~/Sites/craft-rma` (the one the
plugin-testing harness mounts at `/var/www/craft-rma`). Work in `craft-boomerang`, then mirror it into
`craft-rma` before running the suites — `rsync -a --delete --exclude .git --exclude tests/shots
--exclude promos ~/Sites/craft-boomerang/ ~/Sites/craft-rma/` — or the tests run old code. After
mirroring an *older* file back (e.g. to prove a test fails on the previous release), run
`php craft clear-caches/compiled-templates`: rsync keeps the old mtime, and Twig reuses the newer
compiled copy.

## Why it exists

There is no returns plugin for Craft Commerce. Core gives you `Payments::refundTransaction()`
against a payment and nothing else — no request, no approval, no receipt, no restock, no exchange,
no credit. Boomerang is the whole loop, and the store-credit wallet is the part nobody else has:
credit that spends as a real payment method rather than as a discount that lies about the subtotal.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks, no chart library

## Architecture

### Namespace & package

- Namespace: `justinholtweb\boomerang`
- Package: `justinholtweb/craft-boomerang`
- Handle: `boomerang`

### The five invariants

1. **`services\Eligibility::evaluate()` is the only place "can this be returned" is decided.**
   Portal, CP, console and Twig all read one verdict with a per-line trace, so the sentence a
   customer is refused with is the one the merchant sees. Memoized per order per request;
   `forget()` busts it for anything that changes an order mid-process.
2. **`services\Returns::transition()` is the only place an RMA changes state.** Guards, the event
   log, notifications, restock, refund, credit and exchange creation all hang off it, once and in
   one order. Side effects run **before** the state is written, so a refused refund leaves the RMA
   where it was.
3. **`services\Wallet::post()` is the only place a credit ledger row is written.** A balance is
   always `SUM(amountRemaining)` over unexpired lots, never a stored mutable number.
4. **`services\Restock::apply()` is the only place a return moves inventory**, idempotent per item
   through `qtyRestocked`.
5. **`services\Refunds::issue()` is the only place money leaves**, and the one call that fails
   loudly. It writes `refundedAmount` after *every* successful gateway refund, before it throws, so
   a partial success is recorded even when the operation as a whole is not.

### Data model

Eight tables, all hard-deleted, all in the database rather than project config — states and reasons
travel with `boomerang/config export|import`, handles rather than IDs throughout.

| Table | What it is |
| --- | --- |
| `boomerang_returns` | the RMA. Element sub-table. |
| `boomerang_returnitems` | one row per returned line item, with snapshot prices. |
| `boomerang_states` | the state machine. `transitions` is a JSON list of state UIDs. |
| `boomerang_reasons` | why, plus what that implies for restock, fees and analytics. |
| `boomerang_events` | append-only history. |
| `boomerang_labels` | labels from a provider. |
| `boomerang_creditlots` | issued credit, with `amountRemaining` and an expiry. |
| `boomerang_creditentries` | signed movements. Unique on `idempotencyKey`. |

`orderId` is `SET NULL` and the order's number, reference, date and email are copied onto the RMA:
an RMA has to stay answerable after its order is gone.

Prices on a return item are **snapshots**, not lookups through `lineItemId`. A line item's sale
price is recalculated whenever its order is touched, and a refund worked out six weeks later from a
live line item is a refund for a number nobody agreed to.

### The wallet, in detail

Credit is issued in **lots** because it expires; a single running balance cannot answer "how much
of this $60 lapses at the end of the month" without re-deriving which dollars came from which
issue. Spending consumes lots FIFO by expiry, under `SELECT … FOR UPDATE` — a wallet is the one
place in a store where two concurrent requests spending the same money is a real bug. Every caller
supplies an `idempotencyKey`; a duplicate is not an error, it means the movement already happened.

The gateway's `authorize()` places a **hold** (negative entries, `amountRemaining` decremented) and
`capture()` **retypes those same rows** rather than reallocating — a capture that re-drew could
land on different lots, and one that expired in between would silently vanish.

## Traps found while building this

- **A public string property backing an enum shadows its own getter in Twig.** `State::$kind` is
  the raw `'requested'`, so `state.kind.label` throws "Impossible to access an attribute on a
  string variable". Templates must call `state.getKind().label`. Same for `item.getCondition()`,
  `entry.getType()`, `event.getType()`, `return.getResolution()`, `reason.getDefaultCondition()`.
- **`{{ parent() }}` inside `{% block details %}` throws.** `_layouts/cp` extends `_layouts/base`,
  which has no `details` block, so calling `parent()` there is a hard error rather than a no-op.
- **A refusable event must extend `craft\events\CancelableEvent`.** A plain `yii\base\Event` has
  `handled`, not `isValid`, and setting `isValid` on one throws `UnknownPropertyException` rather
  than refusing anything.
- **Restocking is an inventory *adjustment*, not `InventoryRestockMovement`.** That model moves
  `committed → available` and is for an order that was never shipped. Goods coming back from a
  customer had their `committed` quantity consumed by fulfilment long ago; using it would drive
  `committed` negative. Use `UpdateInventoryLevel` with `InventoryUpdateQuantityType::ADJUST`, and
  pick the transaction type from the item's condition.
- **Commerce refuses an address element it does not own** — and `$address->toArray()` carries the
  original's IDs, which is exactly what trips the check. Copy a plain attribute array and let
  Commerce build the owned element.
- **A Commerce transaction's `hash` exists before its `id` does.** The hash is generated in the
  constructor; the ID only exists after `_updateTransaction()` saves it, which happens *after* the
  gateway method has run. So the hash is the only identifier a gateway can write an idempotency key
  against, which is why `boomerang_creditentries.transactionHash` exists.
- **Craft's `HandleValidator` rejects hyphens.** An install migration that seeds `in-transit`
  directly produces rows nobody can ever re-save, import or edit — the insert succeeds because a
  migration bypasses validation, and the failure only shows up the first time somebody opens the
  edit screen. Seed camelCase.
- **Craft soft-deletes users**, so a `CASCADE` foreign key on `users.id` does not fire when a
  customer is deleted. That is correct for credit — a restored customer should get their balance
  back — but it means a test suite has to clean up by `customerId`.
- **`{% set %}` inside a Twig `for` is scoped to the loop body**, so a value accumulated across
  iterations is gone afterwards, and a value assigned per iteration leaks into the next one. And a
  variable named `max` shadows Twig's own `max()` function.
- **A model hydrated from a database row must declare every selected column.** `CreditEntry` was
  missing `dateUpdated`, and `Craft::configure()` threw `UnknownPropertyException` on a row that
  looked perfectly ordinary.
- **Plugins load lazily in a bare console bootstrap.** `Plugin::getInstance()` is null until
  something asks the plugins service for anything, so a script that wants the instance first should
  call `loadPlugins()` explicitly rather than depend on what it happens to touch first.
- **A Commerce gateway has `isFrontendEnabled`, not `enabled`.**
- **The portal must check eligibility per line, not per order.** Until 5.0.1 it checked that the
  *order* had something returnable, then accepted whichever line IDs were posted — an excluded or
  already-returned line included, auto-approved into a refund. `validateLines()` checks each line
  against `Eligibility::availableQty()`, and `Returns::submit(byCustomer: true)` re-checks. CP
  returns deliberately skip this: staff can override eligibility.
- **Craft's `allowedFileExtensions` is not a photo allow-list** — it includes `html`, `svg` and `js`.
  Portal photos use their own image list plus a `finfo` MIME check, and are only stored after the
  whole request validates.
- **State email subject/body are admin-only** (they are unsandboxed Twig). `StatesController` only
  takes them from an admin; a non-admin save keeps them.
- **Commerce 5 attaches a customer user to every order**, guest checkouts included — so "guest
  order" is not a way to get an order with no customer, and a guard against a missing customer has
  to be tested against an RMA whose `customerId` is null.

See also `[[craft-plugin-gotchas]]` and `[[craft-commerce-shipping-gotchas]]` in the shared memory
for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container, and **`docker exec`,
not `ddev exec`** — `ddev exec` re-checks that the project is running and times out mid-suite.

```sh
cd ~/Sites/plugin-testing
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/integration/checks.php   # 117
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/integration/portal.php   # 6, the portal over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/integration/trust.php    # 2, a non-admin editing state emails
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-rma/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

**117 checks, 0 failures**, idempotent and self-cleaning. It switches to Pro in memory for the bulk
of the run and exercises Lite in its own section. It includes real Commerce orders and
transactions, a real refund through the Dummy gateway, real inventory transactions, a real payment
through the store-credit gateway, and the wallet's allocation under its own lock.

`_fixtures.php` holds the product/order/payment/customer builders and the sibling-plugin
workarounds; `checks.php` and `portal.php` both require it. `portal.php` and `trust.php` *persist*
the settings they need (the HTTP side is another process) and restore them in a shutdown handler.

Settings and edition changes in `checks.php` are made **in memory** (`Settings::setAttributes()`,
`$plugin->edition`), never through project config: project config is contended in the shared
harness and a suite that writes it fails for reasons that have nothing to do with the plugin.

### Sibling plugins that break the harness, not Boomerang

The suite detaches two of these in-process; the third had to be disabled to smoke-test the CP.

- **`craft-penny`** types its `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler `ModelEvent` while
  Craft passes an `ElementEvent`, so **every element save fatals** while it is enabled.
- **`craft-lyfe`** hooks `Order::EVENT_AFTER_COMPLETE_ORDER` and reads a `phone` custom field that
  does not exist there, so **completing any order throws**. Detaching it removes every handler on
  that event, so Boomerang's own exchange-remainder handler is re-attached immediately.
- **`craft-csr`** declares `Ticket::getStatus(): ?Status`, incompatible with
  `Element::getStatus(): ?string` — a PHP **fatal at class load**, so any CP page that enumerates
  element types 500s. It was disabled for the CP smoke test and re-enabled afterwards.
- **`craft-my`**'s order-panel template has a Twig syntax error that 500s every Commerce order edit
  screen, so verify an order-panel hook by rendering the template directly.

### What was smoke-tested through a real session

Returns index and detail, states index/edit/new, reasons index/edit/new, credit index and detail,
analytics, plugin settings, and the user edit screen — all 200. The Commerce order panel, the user
panel, the bulk-action trigger, all three emails and both gateway templates were rendered directly
(the order screen itself is broken by `craft-my`). The whole customer portal was exercised over
HTTP: guest lookup → request form → submit → status page, plus a wrong token 404ing and a wrong
email being refused with the same message as a wrong number.

The harness is left on the **Pro** edition.

## Coding conventions

- `Craft::t('boomerang', '…')` for user-facing strings; `src/translations/en/boomerang.php` lists
  every one of them
- Business logic in services; controllers resolve, check a permission, and delegate
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Nothing on `craft.boomerang` writes
- Anything that runs during checkout fails **closed on money and open on chrome**: a wallet that
  cannot cover a payment refuses it outright rather than partially, and a mail server that is down
  never stops a state change that has already moved money
