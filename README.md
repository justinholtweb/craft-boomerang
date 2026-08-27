<p><img src="src/icon.svg" width="72" alt="Boomerang"></p>

# Boomerang

**Returns, RMAs and a store-credit wallet for Craft Commerce 5.**

Craft Commerce can refund a payment. That is all it can do about a return. Everything else — the
customer asking, the merchant deciding, the goods coming back, the stock going back on the shelf,
the money going back as cash, credit or replacement goods — is left to a shared inbox and a
spreadsheet, or to a developer building the same half of it again.

Boomerang is the whole loop.

- A **customer returns portal**: look an order up by number and email, choose what is going back
  and why, get a status page and a return label.
- A **configurable RMA state machine** — requested → approved → received → resolved is the shape,
  not the vocabulary — with a full history of everything that happened to every return.
- **Restock into Commerce 5 inventory locations**, to the right shelf: resellable goods to
  available, damaged goods to damaged, anything questionable to quality control.
- **Exchange and replacement orders** that carry a credit for what came back, so the customer pays
  only the difference.
- **Refund to store credit as a wallet that spends like a payment method** — the customer sees
  "Store credit — $40.00" beside the card at checkout, and the merchant sees a real transaction.
- **Carrier return labels** through a pluggable provider, with a manual provider that makes no
  outbound request at all.
- **Return analytics** that separate "we got it wrong" from "they ordered two sizes on purpose",
  because a return rate that mixes the two tells you nothing.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Installation

```sh
composer require justinholtweb/craft-boomerang
php craft plugin/install boomerang
```

The install seeds a working state machine (Requested, Approved, In transit, Received, Resolved,
Rejected, Cancelled) and eight return reasons, so the plugin does something on the first screen you
open rather than after an afternoon of configuration.

## Editions

|                                   | Lite (free) | Pro ($129) |
| --------------------------------- | :---------: | :--------: |
| Customer returns portal           | ● | ● |
| RMA state machine and history     | ● | ● |
| Reasons, eligibility, the window  | ● | ● |
| Refunds through Commerce          | ● | ● |
| Restocking fees                   | ● | ● |
| Notifications                     | ● | ● |
| **Store-credit wallet + gateway** |   | ● |
| **Exchange and replacement orders** |  | ● |
| **Restock into inventory locations** |  | ● |
| **Carrier return labels**         |   | ● |
| **Return analytics**              |   | ● |

Pro settings that survive a downgrade are **ignored, not obeyed**, and the control panel says which
ones. Nothing is lost by upgrading later.

## The state machine

States are yours to name. What the plugin reads is each state's **kind** — requested, approved,
awaiting, received, resolved, rejected, cancelled — so renaming "Approved" to "Authorised" is a
label change and not a bug. Each state can:

- allow moves only to particular other states (leave the list empty for "anywhere", which is the
  escape hatch a returns desk needs at five o'clock on a Friday)
- email the customer on arrival, with your own subject and body
- put the goods back into inventory on arrival
- carry out the return's resolution on arrival — the refund, the credit, the exchange order
- offer the customer a return label

A refund that the gateway refuses **aborts the transition**: the RMA stays exactly where it was,
and the reason is on its history. It is never marked resolved with no money having moved.

## The wallet

Store credit is a payment method, not a discount. Add a **Store Credit (Boomerang)** gateway in
Commerce → Settings → Gateways and it appears at checkout for any signed-in customer with a
balance.

- **Partial payment** is the normal case: a $40 balance against a $95 order pays $40 and the card
  pays $55.
- Credit is issued in **lots**, each with its own expiry, and spending consumes them oldest-expiry
  first — so the money about to lapse is always the money spent first.
- A refund of a wallet payment goes back into the **exact lots it came from**, unless one has
  expired meanwhile, in which case a fresh lot is issued. Refunding a customer into money they
  cannot spend is not a refund.
- Expiry is enforced in the balance itself as well as by `boomerang/credit/expire`, so an unrun
  cron cannot let expired credit be spent.
- `creditBonusPercent` is the lever that makes credit the resolution customers pick: 10 turns a $50
  refund into $55 of credit.

## The portal

Enabled by default at `/returns`. A signed-in customer sees their own orders; a guest looks an
order up by **number and email together** — never the number alone.

Every portal page is an ordinary Twig template under `boomerang/_portal/`. Craft looks in your own
templates folder first, so restyling it is a matter of copying a file:

```sh
cp vendor/justinholtweb/craft-boomerang/src/templates/_portal/_base.twig \
   templates/boomerang/_portal/_base.twig
```

The URLs do not move, and nothing has to be configured. The emails under `boomerang/_emails/` work
the same way.

To route it yourself instead, clear the "Mounted at" setting and post to the action endpoints:
`boomerang/portal/lookup`, `boomerang/portal/submit`, `boomerang/portal/cancel`,
`boomerang/portal/request-label`.

## Twig

```twig
{# Can this order be returned, and which of it? #}
{% set verdict = craft.boomerang.eligibility(order) %}
{% if verdict.isEligible %}
    <a href="{{ craft.boomerang.portalUrl }}">Start a return</a>
    <p>{{ verdict.daysRemaining }} days left</p>
{% else %}
    <p>{{ verdict.firstMessage }}</p>
{% endif %}

{# The signed-in customer's returns and balance #}
{% for return in craft.boomerang.myReturns() %}
    {{ return.reference }} — {{ return.state.name }}
{% endfor %}

<p>Store credit: {{ craft.boomerang.balance|commerceCurrency(cart.currency) }}</p>

{% set expiring = craft.boomerang.expiringBalance() %}
{% if expiring %}
    <p>{{ expiring|commerceCurrency(cart.currency) }} expires soon.</p>
{% endif %}

{# Returns as an element query #}
{% set open = craft.boomerang.returns().isOpen(true).all() %}
```

Everything on `craft.boomerang` is a read. Nothing in Twig can move a return, spend credit or issue
a refund — a template that could would be a template that does, the first time somebody cached a
page.

## Console

```sh
php craft boomerang/returns/list --state=approved
php craft boomerang/returns/show RMA-00042
php craft boomerang/returns/transition RMA-00042 received
php craft boomerang/returns/stalled --days=14   # what the desk should read on a Monday
php craft boomerang/returns/prune --days=1095 --dryRun=1

php craft boomerang/credit/expire               # put this in cron
php craft boomerang/credit/warn
php craft boomerang/credit/release-holds        # give back uncaptured authorizations
php craft boomerang/credit/balance someone@example.com

php craft boomerang/labels/providers
php craft boomerang/labels/preview RMA-00042    # the exact carrier request, without buying it

php craft boomerang/analytics/report --days=90

php craft boomerang/config/export --path=boomerang.json
php craft boomerang/config/import --path=boomerang.json
```

States and reasons live in the database rather than in project config, and travel between
environments through `boomerang/config`. Handles rather than IDs throughout, including inside the
transition lists.

## Extending

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

Register your own label provider on `Labels::EVENT_REGISTER_LABEL_PROVIDERS` by implementing
`justinholtweb\boomerang\labels\LabelProviderInterface`.

## Return labels

Two providers ship:

- **Manual** — paste a tracking number and attach the label. Makes no outbound request at all, and
  is what most stores will actually use.
- **ShipStation** — v2 REST, `is_return_label`. The base URL and `API-Key` header are verified; the
  label request and response shapes are implemented from ShipEngine's published contract and have
  not been exercised against a live account. `boomerang/labels/preview` prints exactly what would
  be sent, so you can check it before spending anything.

Labels are copied into a Craft volume when one is configured, because carriers expire their
download links and a customer opening their status page a fortnight later needs the label rather
than a 403.

## Privacy and security

- The portal's guest lookup needs the order number **and** the email, and answers "no such order"
  and "wrong email" with the same sentence — telling them apart turns the form into an
  order-number oracle.
- It is rate limited per IP per hour.
- A return's status page is behind a 32-character token compared in constant time, and is served
  `noindex, nofollow`.
- Refunds and credit need a separate permission from managing returns, because moving an RMA along
  and paying a customer are different amounts of trust.
- An RMA survives its order: the order's number, reference, date and email are copied onto it and
  `orderId` is `SET NULL`. In several jurisdictions "what did we do about this return" is a
  question a merchant is required to be able to answer.

## Licence

See [LICENSE.md](LICENSE.md).
