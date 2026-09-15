---
title: Troubleshooting
slug: troubleshooting
order: 80
summary: The things that go wrong, and what each one actually means.
---

## "This order can't be returned" and the customer disagrees

Run the verdict yourself:

```sh
php craft boomerang/returns/show RMA-00042
```

Eligibility is one verdict with a **per-line trace**, so it tells you which line was refused and
why, rather than only that something was. The usual causes, in order of how often they happen:

1. the order is outside `returnWindowDays`, or outside the reason's own window
2. the line has already been returned in full on an earlier RMA
3. the order is not complete
4. the line is a shipping or discount adjustment rather than a purchasable

## The customer says the portal won't find their order

A guest lookup needs the order number **and** the email together, and a wrong number and a wrong
email are answered with the same sentence — deliberately, so the form cannot be used to enumerate
order numbers. So "no such order" genuinely can mean "the email is wrong".

The lookup is also rate limited per IP per hour. A customer who has just tried eleven times will be
refused even with the right details.

## A transition refused and the RMA didn't move

That is the design. Side effects run **before** the state is written, so a refund the gateway
refuses aborts the whole transition — the RMA stays where it was and the reason is on its history.

Read the history panel on the RMA: the failure is a row on it, with the gateway's own message.

## A refund partially went through

`services\Refunds::issue()` writes `refundedAmount` after **every** successful gateway refund, before
it throws. An order paid across three transactions where the second is refused therefore records the
first refund and stops.

The RMA will show a `refundedAmount` lower than its `resolutionAmount`. Refund the remainder from
the order's own transactions in Commerce, then move the RMA on.

## Restocking ran twice

It didn't. `Restock::apply()` is idempotent per item through `qtyRestocked`. If the numbers look
wrong, check whether something else in the install is also adjusting inventory on the same event.

## Inventory went negative

If `committed` is negative, something is using `InventoryRestockMovement` for goods that were already
fulfilled — that model moves `committed → available` and is for an order that was never shipped.
Boomerang uses an ADJUST-type `UpdateInventoryLevel`, which cannot cause this.

## "Impossible to access an attribute on a string variable"

A public string property backing an enum shadows its own getter in Twig. Use `state.getKind()`,
`item.getCondition()`, `return.getResolution()`, `event.getType()`,
`reason.getDefaultCondition()` — see [Enums in templates](usage#enums-in-templates).

## A state or reason can't be saved after an import

Craft's `HandleValidator` rejects hyphens. A row seeded or imported with a handle like `in-transit`
inserts fine — a migration and an import both bypass validation — and then cannot ever be re-saved
from the edit screen.

Rename the handle to camelCase (`inTransit`). Transition lists are stored as handles, so fix them in
the same pass or the list will point at nothing.

## The store-credit gateway isn't at checkout

Check, in this order:

1. the plugin is on **Pro** — the wallet is a Pro feature
2. a **Store Credit (Boomerang)** gateway exists in Commerce → Settings → Gateways
3. the gateway's **`isFrontendEnabled`** is on — that is the property, not `enabled`
4. the customer is **signed in** — a wallet belongs to a customer, not to a cart
5. the customer's balance is above zero, counting only **unexpired** lots

## Credit vanished

Two possibilities, and the history tells them apart:

- **it expired** — expiry is enforced in the balance on read, not only by the cron, so an unrun
  `boomerang/credit/expire` does not let expired credit be spent
- **it is on hold** — an authorization that was never captured holds the money. Release them with
  `php craft boomerang/credit/release-holds`

## A deleted customer's credit disappeared, then came back

Craft **soft**-deletes users, so the `CASCADE` foreign key on `users.id` does not fire when a
customer is deleted. That is correct for credit: a restored customer gets their balance back.

## An RMA whose order is gone

Expected, and supported. `orderId` is `SET NULL`, and the order's number, reference, date and email
are copied onto the RMA when it is created. In several jurisdictions "what did we do about this
return" is a question a merchant is required to be able to answer after the order itself has aged
out.

## Emails aren't arriving

A state change never fails because mail did. Anything that runs during a transition fails closed on
money and open on chrome, so a dead mail server logs a row on the RMA's history and otherwise lets
the transition through. Check the history panel — the attempt is recorded there.
