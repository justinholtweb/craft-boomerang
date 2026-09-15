---
title: Twig and the console
slug: usage
order: 70
summary: Everything on craft.boomerang, the console commands a returns desk actually runs, and the analytics screen.
---

## Eligibility

```twig
{% set verdict = craft.boomerang.eligibility(order) %}

{% if verdict.isEligible %}
    <a href="{{ craft.boomerang.portalUrl }}">Start a return</a>
    <p>{{ verdict.daysRemaining }} days left</p>
{% else %}
    <p>{{ verdict.firstMessage }}</p>
{% endif %}
```

`services\Eligibility::evaluate()` is the only place "can this be returned" is decided. The portal,
the control panel, the console and Twig all read one verdict, with a per-line trace — so the sentence
a customer is refused with is the one the merchant sees.

It is memoized per order per request. Anything that changes an order mid-process calls `forget()`.

## A customer's returns and balance

```twig
{% for return in craft.boomerang.myReturns() %}
    {{ return.reference }} — {{ return.state.name }}
{% endfor %}

<p>Store credit: {{ craft.boomerang.balance|commerceCurrency(cart.currency) }}</p>
```

## Returns as an element query

```twig
{% set open = craft.boomerang.returns().isOpen(true).all() %}
```

Returns are Craft elements, so the usual query methods work.

## Nothing on `craft.boomerang` writes

Everything on `craft.boomerang` is a read. Nothing in Twig can move a return, spend credit or issue
a refund.

A template that could would be a template that does — the first time somebody cached a page, or a
crawler followed a link, or a template got rendered twice by a job.

## Enums in templates

A public string property backing an enum shadows its own getter in Twig. `state.kind` is the raw
string `'requested'`, so `state.kind.label` throws. Call the getter:

```twig
{{ state.getKind().label }}
{{ item.getCondition().label }}
{{ return.getResolution().label }}
{{ event.getType().label }}
```

Same for `entry.getType()` and `reason.getDefaultCondition()`.

## The console

```sh
php craft boomerang/returns/list --state=approved
php craft boomerang/returns/show RMA-00042
php craft boomerang/returns/transition RMA-00042 received
php craft boomerang/returns/stalled --days=14   # what the desk should read on a Monday
php craft boomerang/returns/prune --days=1095 --dryRun=1

php craft boomerang/credit/expire               # put this in cron
php craft boomerang/credit/warn
php craft boomerang/credit/release-holds
php craft boomerang/credit/balance someone@example.com

php craft boomerang/labels/providers
php craft boomerang/labels/preview RMA-00042    # the exact carrier request, without buying it

php craft boomerang/analytics/report --days=90

php craft boomerang/config/export --path=boomerang.json
php craft boomerang/config/import --path=boomerang.json
```

`boomerang/returns/transition` goes through the same
[`Returns::transition()`](states#one-door) the control panel does — the same guards, the same
emails, the same restock, the same refund.

### The two to put in cron

```
0 3 * * *  php craft boomerang/credit/expire
0 9 * * 1  php craft boomerang/credit/warn
```

## Analytics

**Pro.** The analytics screen separates "we got it wrong" from "they ordered two sizes on purpose",
because a return rate that mixes the two tells you nothing.

It reports:

- return count, value and rate over the window, against how many were **sold** in the same period
- the share that were the store's own fault, driven by the [reason](states#reasons)
- median days from request to settled
- what proportion of the goods went back on the shelf
- every reason ranked by what it cost
- every SKU, with its own return rate against its own sales
- how returns were settled — refund, credit, exchange, replacement
- store credit issued, spent, expired, outstanding and redeemed

Credit issued and never redeemed is revenue the store kept; credit redeemed is a customer who came
back, which is the argument for offering it in the first place.

## Return labels

**Pro.** Two providers ship:

- **Manual** — paste a tracking number and attach the label. Makes no outbound request at all, and
  is what most stores will actually use.
- **ShipStation** — v2 REST, `is_return_label`.

Register your own on `Labels::EVENT_REGISTER_LABEL_PROVIDERS` by implementing
`justinholtweb\boomerang\labels\LabelProviderInterface`.

Labels are copied into a Craft volume when one is configured, because carriers expire their download
links and a customer opening their status page a fortnight later needs the label rather than a 403.

`boomerang/labels/preview` prints exactly what would be sent to the carrier without buying anything.
