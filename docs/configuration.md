---
title: Configuration
slug: configuration
order: 20
summary: The settings screen, the return window, fees, and moving states and reasons between environments.
---

Settings live at **Settings → Plugins → Boomerang**. None of them are required: every one has a
working default, and the plugin runs on the defaults.

## The return window

`returnWindowDays` is how long after an order is completed a customer may start a return. It is the
first thing [eligibility](usage#eligibility) checks, and the one customers ask about most.

A reason can override it. "Arrived damaged" with a longer window than "changed my mind" is the usual
shape, and it is set per reason rather than globally.

## Fees

`restockingFeePercent` is deducted from what the customer gets back. A reason can waive it — you do
not charge a restocking fee on goods that arrived broken — and the waiver is a switch on the reason
rather than something the desk has to remember.

The fee is worked out against the **snapshot** price on the return item, not against the line item's
current sale price. See [Prices are snapshots](#prices-are-snapshots).

## Store credit

Pro. Three settings carry the wallet:

- `creditExpiryDays` — how long a lot lasts. `0` means it never expires.
- `creditBonusPercent` — the lever that makes credit the resolution customers pick. `10` turns a $50
  refund into $55 of credit.
- `creditWarnDays` — how far ahead `boomerang/credit/warn` looks when it emails people about credit
  about to lapse.

See [The store-credit wallet](wallet).

## Notifications

Each state carries its own subject and body, edited on the state rather than in settings, so the
email a customer gets when a return is approved is written next to the thing that approves it.

Templates live under `boomerang/_emails/` and Craft looks in your own templates folder first, so
restyling them is a matter of copying a file.

A mail server that is down never stops a state change. Anything that runs during checkout or a
transition **fails closed on money and open on chrome**: a wallet that cannot cover a payment
refuses it outright, and an email that cannot be sent is logged on the RMA's history and otherwise
ignored.

## Prices are snapshots

A return item stores what each line was worth **at the moment the return was created**, rather than
looking it up through `lineItemId` later.

This is deliberate and it matters: a line item's sale price is recalculated whenever its order is
touched, so a refund worked out six weeks later from a live line item is a refund for a number
nobody agreed to.

## Moving states and reasons between environments

States and reasons live in the **database**, not in project config, and travel with:

```sh
php craft boomerang/config/export --path=boomerang.json
php craft boomerang/config/import --path=boomerang.json
```

Handles rather than IDs throughout, including inside the transition lists, so an import lands on a
database whose IDs are nothing like the source's.

They are not in project config on purpose. A returns desk edits states and reasons the way it edits
content — at five o'clock, in production, because something changed — and project config makes that
either impossible or a deploy.
