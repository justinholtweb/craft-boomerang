---
title: The customer portal
slug: portal
order: 30
summary: Where customers start a return, how a guest is looked up, and how to restyle or re-route the whole thing.
---

Enabled by default at `/returns`. A signed-in customer sees their own orders; a guest looks an order
up by number and email.

## The five screens

1. **Look up** — order number plus email, rate limited per IP per hour.
2. **Choose** — which lines go back, how many of each, and why.
3. **Submit** — one eligibility verdict decides what may be returned, per line.
4. **Track** — a status page with the full history of the return.
5. **Ship** — a return label, if the state the RMA is in offers one.

## Guest lookup is deliberately unhelpful

A guest must supply the order number **and** the email together — never the number alone — and a
wrong number and a wrong email are answered with **the same sentence**.

Telling them apart turns the form into an order-number oracle: an attacker who can distinguish "no
such order" from "wrong email" can enumerate your order numbers and learn which ones exist. The
error message is identical on purpose, and there is a test that asserts it.

The lookup is rate limited per IP per hour on top of that.

## Status pages

A return's status page sits behind a **32-character token**, compared in constant time, and is
served `noindex, nofollow`. The URL is what goes in the customer's email; there is nothing else
guarding it, which is why the comparison is constant-time and the token is that long.

## Restyling it

Every portal page is an ordinary Twig template under `boomerang/_portal/`. Craft looks in your own
templates folder first, so restyling is a matter of copying a file:

```sh
cp vendor/justinholtweb/craft-boomerang/src/templates/_portal/_base.twig \
   templates/boomerang/_portal/_base.twig
```

The URLs do not move and nothing has to be configured. The emails under `boomerang/_emails/` work
the same way.

## Routing it yourself

Clear the **Mounted at** setting and the portal stops answering at `/returns`. The action endpoints
keep working, so you can build the whole thing into your own front end and post to:

| Endpoint | What it does |
| --- | --- |
| `boomerang/portal/lookup` | order number + email → a session for that order |
| `boomerang/portal/submit` | creates the RMA |
| `boomerang/portal/cancel` | cancels a return the customer started |
| `boomerang/portal/request-label` | asks the configured provider for a label |

## Showing eligibility on your own pages

You do not have to send a customer to the portal to find out whether they can return something:

```twig
{% set verdict = craft.boomerang.eligibility(order) %}

{% if verdict.isEligible %}
    <a href="{{ craft.boomerang.portalUrl }}">Start a return</a>
    <p>{{ verdict.daysRemaining }} days left</p>
{% else %}
    <p>{{ verdict.firstMessage }}</p>
{% endif %}
```

`craft.boomerang.eligibility()` is the same verdict the portal, the control panel and the console
read. The sentence a customer is refused with is the one the merchant sees on the RMA — there is no
second implementation that can drift.
