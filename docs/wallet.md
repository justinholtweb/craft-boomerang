---
title: The store-credit wallet
slug: wallet
order: 50
summary: Credit that spends as a payment method, why it is issued in lots, and how refunds of a wallet payment behave.
---

**Pro.** Store credit is a payment method, not a discount.

Add a **Store Credit (Boomerang)** gateway in Commerce → Settings → Gateways and it appears at
checkout for any signed-in customer with a balance. The customer sees "Store credit — $40.00" beside
the card; the merchant sees a real Commerce transaction against a real gateway.

That distinction is the whole feature. A discount that stands in for credit lies about the subtotal,
and every report downstream — tax, revenue, the order itself — inherits the lie.

## Partial payment is the normal case

A $40 balance against a $95 order pays $40, and the card pays the other $55. Nothing special has to
be configured for this; it is what the gateway does.

## Lots, not a balance

Credit is issued in **lots**, each with its own expiry. A balance is always `SUM(amountRemaining)`
over unexpired lots — never a stored, mutable number.

It works this way because credit expires. A single running balance cannot answer "how much of this
$60 lapses at the end of the month" without re-deriving which dollars came from which issue, and
that is exactly the question a customer service desk gets asked.

Spending consumes lots **oldest-expiry first**, so the money about to lapse is always the money
spent first.

## Concurrency

Allocation runs under `SELECT … FOR UPDATE`. A wallet is the one place in a store where two
concurrent requests spending the same money is a real bug rather than a theoretical one, and the
lock is not optional.

Every caller supplies an `idempotencyKey`. A duplicate is **not an error** — it means the movement
already happened, and the wallet says so rather than double-spending.

## Holds and captures

The gateway's `authorize()` places a **hold**: negative entries, with `amountRemaining` decremented
on the lots it drew from. `capture()` then **retypes those same rows** rather than reallocating.

Reallocating at capture time would be wrong in two ways: a second draw could land on different lots,
and a lot that expired between the authorization and the capture would silently vanish from a
payment the customer had already been promised.

Authorizations that are never captured are given back by `boomerang/credit/release-holds`.

## Refunding a wallet payment

A refund of a wallet payment goes back into the **exact lots it came from** — unless one of them has
expired in the meantime, in which case a fresh lot is issued for that portion.

Refunding a customer into money they cannot spend is not a refund.

## Expiry

Expiry is enforced **in the balance itself** as well as by the cron:

```sh
php craft boomerang/credit/expire   # put this in cron
php craft boomerang/credit/warn     # emails people whose credit is about to lapse
```

The balance filters expired lots on read, so an unrun cron cannot let expired credit be spent. The
command exists to write the expiry entries and free the rows, not to enforce the rule.

## The bonus

`creditBonusPercent` is the lever that makes credit the resolution customers pick: set it to `10`
and a $50 refund becomes $55 of credit. The bonus is applied when the credit is issued, not when the
return's amount is worked out, so the RMA keeps saying what the goods were actually worth.

## In Twig

```twig
<p>Store credit: {{ craft.boomerang.balance|commerceCurrency(cart.currency) }}</p>

{% set expiring = craft.boomerang.expiringBalance() %}
{% if expiring %}
    <p>{{ expiring|commerceCurrency(cart.currency) }} expires soon.</p>
{% endif %}
```

Everything on `craft.boomerang` is a read. Nothing in Twig can spend credit — see
[Twig and the console](usage#nothing-on-craftboomerang-writes).

## From the console

```sh
php craft boomerang/credit/balance someone@example.com
php craft boomerang/credit/release-holds
```
