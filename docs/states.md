---
title: The state machine
slug: states
order: 40
summary: States, kinds, transitions, what happens on arrival, and why a refused refund leaves the RMA alone.
---

States are yours to name. What the plugin reads is each state's **kind**.

## Kinds, not labels

Seven kinds: `requested`, `approved`, `awaiting`, `received`, `resolved`, `rejected`, `cancelled`.

Everything the plugin decides — whether a return is open, whether the goods are back, whether the
money has moved — is decided from the kind. Renaming "Approved" to "Authorised", or running three
separate states of kind `awaiting` because your warehouse has three waiting rooms, is a label change
and not a bug.

## What a state can do

Each state carries four switches, applied on arrival, in this order:

1. **Email the customer**, with that state's own subject and body.
2. **Restock**, putting the goods back into inventory.
3. **Resolve**, carrying out the refund, credit or exchange.
4. **Offer a label**, making the label button appear on the customer's status page.

## Transitions

Each state holds a list of the states it may move to. **Leave the list empty for "anywhere"** —
which is the escape hatch a returns desk needs at five o'clock on a Friday, when the goods are on
the counter and the RMA is in the wrong state.

Transition lists are stored as handles rather than IDs, so they survive
[`boomerang/config/import`](configuration#moving-states-and-reasons-between-environments) onto a
database whose IDs are nothing like the source's.

## One door

`services\Returns::transition()` is the only place an RMA changes state. Guards, the event log,
notifications, restock, refund, credit and exchange creation all hang off it, once and in one order.

**Side effects run before the state is written.** A refund the gateway refuses therefore **aborts the
transition**: the RMA stays exactly where it was, and the reason is on its history. It is never
marked resolved with no money having moved.

## History

Every move is appended to the RMA's event log — who moved it, when, from what to what, and anything
the move did: the restock and its location, the credit lot and its expiry, the refund and its
transaction, the email and its template.

The log is append-only. Nothing in the control panel edits or removes a row.

## Refusing a transition from your own code

```php
use justinholtweb\boomerang\services\Returns;
use justinholtweb\boomerang\events\ReturnStateEvent;
use yii\base\Event;

// Hold anything over $500 for a human.
Event::on(Returns::class, Returns::EVENT_BEFORE_TRANSITION, function(ReturnStateEvent $event) {
    if ($event->toState->resolveOnEnter && $event->return->resolutionAmount > 500) {
        $event->isValid = false;
    }
});
```

`ReturnStateEvent` extends `craft\events\CancelableEvent`, so `isValid` is what refuses it. Setting
`handled` does nothing here.

## Reasons

A reason is why the goods came back, plus what that implies:

- the **condition** the goods are assumed to arrive in, which decides the shelf they are restocked to
- whether the **restocking fee** is waived
- whether the return **counts as the store's fault** in [analytics](usage#analytics)
- optionally, its own **return window**, longer or shorter than the global one

Separating "we got it wrong" from "they ordered two sizes on purpose" is the whole point of the
analytics screen, and it is a switch on the reason because nothing else knows.
