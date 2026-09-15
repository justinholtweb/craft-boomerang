---
title: Installation
slug: installation
order: 10
summary: Requirements, install, what the install seeds, and the editions.
---

Two commands. The install seeds a working state machine and eight return reasons, so the plugin does
something on the first screen you open rather than after an afternoon of configuration.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+

No other dependencies, no build step and no external service. The store-credit gateway and the
manual label provider both run entirely inside your own install.

## Install

```sh
composer require justinholtweb/craft-boomerang
php craft plugin/install boomerang
```

## What the install seeds

Seven states:

| State | Kind | On arrival |
| --- | --- | --- |
| Requested | `requested` | emails the customer |
| Approved | `approved` | emails the customer, offers a label |
| In transit | `awaiting` | offers a label |
| Received | `received` | emails the customer, restocks |
| Resolved | `resolved` | emails the customer, carries out the resolution |
| Rejected | `rejected` | emails the customer |
| Cancelled | `cancelled` | — |

And eight reasons — doesn't fit, not as described, changed my mind, arrived damaged, faulty, wrong
item sent, arrived too late, something else — each carrying what it implies for restocking, fees and
analytics.

All of it is yours to rename, reorder, delete or replace. See [The state machine](states) and
[Configuration](configuration).

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
ones. Nothing is lost by upgrading later: a store that ran on Pro, lapsed, and upgraded again finds
its credit lots and its label history exactly where it left them.

## Permissions

Boomerang adds its own permissions, and separates two levels of trust:

- **Manage returns** — see RMAs, move them along, edit items and notes.
- **Issue refunds and credit** — pay a customer.

They are separate because moving an RMA along and taking money out of the store are different
amounts of trust, and a returns desk usually wants the first for everybody and the second for two
people.

## Turning the portal off

The portal is enabled at `/returns` by default. Clear the **Mounted at** setting to unmount it — the
action endpoints keep working, so you can route it yourself. See [The customer portal](portal).
