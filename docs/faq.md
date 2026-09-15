---
title: FAQ
slug: faq
order: 90
summary: The questions worth answering before you install it.
---

## Does Craft Commerce not already do returns?

It refunds payments. `Payments::refundTransaction()` against a transaction is the whole of it —
there is no request, no approval, no receipt, no restock, no exchange and no credit. Everything
between a customer wanting to send something back and the money or goods moving is left to a shared
inbox and a spreadsheet, or to a developer building the same half of it again.

## What do I actually get in the free edition?

The whole loop, minus the money-adjacent extras: the customer portal, the RMA state machine and its
history, reasons and eligibility and the return window, refunds through Commerce, restocking fees
and notifications.

Pro adds the store-credit wallet and its gateway, exchange and replacement orders, restocking into
Commerce 5 inventory locations, carrier return labels and the analytics screen.

## Is store credit a discount?

No, and that is the point. It is a Commerce **payment method** with its own gateway. The customer
sees "Store credit — $40.00" beside the card at checkout, and the merchant sees a real transaction
against a real gateway.

A discount standing in for credit lies about the subtotal, and every report downstream — tax,
revenue, the order itself — inherits the lie.

## Can store credit pay for part of an order?

That is the normal case. A $40 balance against a $95 order pays $40 and the card pays $55. Nothing
needs configuring for it.

## Why is credit issued in "lots"?

Because it expires. A single running balance cannot answer "how much of this $60 lapses at the end
of the month" without re-deriving which dollars came from which issue — and that is the question a
service desk gets asked. Lots make it a lookup.

Spending consumes them oldest-expiry first, so the money about to lapse is always the money spent
first.

## What happens if a customer returns something bought with credit?

The refund goes back into the exact lots it came from, unless one has expired in the meantime, in
which case a fresh lot is issued for that portion. Refunding a customer into money they cannot spend
is not a refund.

## Can I rename the states?

Yes. The plugin reads each state's **kind** — requested, approved, awaiting, received, resolved,
rejected, cancelled — not its label. Rename "Approved" to "Authorised", or run three separate states
of kind `awaiting` because your warehouse has three waiting rooms, and nothing breaks.

## Do states and reasons travel between environments?

Through `boomerang/config export` and `import`, as one JSON file, using handles rather than IDs
throughout.

They are deliberately **not** in project config: a returns desk edits states and reasons the way it
edits content — at five o'clock, in production, because something changed — and project config makes
that either impossible or a deploy.

## What happens if a refund fails?

The transition aborts. Side effects run before the state is written, so the RMA stays exactly where
it was and the gateway's reason goes on its history. An RMA is never marked resolved with no money
having moved.

## Can I restyle the portal?

Every portal page is an ordinary Twig template under `boomerang/_portal/`, and Craft looks in your
own templates folder first — so copy the file you want to change and edit it. The URLs do not move
and nothing has to be configured. The emails work the same way.

You can also unmount the portal entirely and post to the action endpoints from your own front end.

## Is the guest lookup safe?

A guest needs the order number **and** the email together, never the number alone, and a wrong
number and a wrong email are answered with the same sentence — telling them apart would turn the
form into an order-number oracle. It is rate limited per IP per hour on top of that, and a status
page sits behind a 32-character token compared in constant time and served `noindex, nofollow`.

## Can my templates issue a refund or spend credit?

No. Everything on `craft.boomerang` is a read. A template that could write would be a template that
does, the first time somebody cached a page.

## Which carriers are supported for return labels?

A **manual** provider, which makes no outbound request at all and is what most stores will actually
use, and **ShipStation** v2 REST. You can register your own provider by implementing
`LabelProviderInterface`.

The ShipStation integration's base URL and `API-Key` header are verified; the label request and
response shapes are implemented from ShipEngine's published contract and have not been exercised
against a live account. `boomerang/labels/preview` prints exactly what would be sent so you can
check it before spending anything.

## What happens to a return when its order is deleted?

It survives. `orderId` is `SET NULL`, and the order's number, reference, date and email are copied
onto the RMA when it is created — because in several jurisdictions "what did we do about this
return" is a question a merchant has to be able to answer after the order has aged out.

## Does it work with multiple stores or sites?

Yes. Credit lots carry a store and a currency, and a balance is per store. Returns record the site
they were submitted from.

## What if I upgrade to Pro later, or lapse?

Nothing is lost either way. Pro settings that survive a downgrade are **ignored, not obeyed**, and
the control panel says which ones. A store that lapses and upgrades again finds its credit lots and
its label history where it left them.
