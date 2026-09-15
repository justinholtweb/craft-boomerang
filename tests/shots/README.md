# Screenshot harness

Drives `~/Sites/plugin-testing` into a state worth photographing, so the images on the marketing
site and in `promos/shots/` are real captures of a real install rather than mockups.

Everything here goes through the plugin's own services. The RMAs are walked along the state machine
one `Returns::transition()` at a time, which means real emails, real inventory adjustments, real
refunds through the Dummy gateway and real credit lots — because the history panel is one of the
screens being photographed, and an RMA whose `stateId` was set behind the service's back has no
history at all.

## Running it

The repo is mounted into the container as **`/var/www/craft-rma`**, not `craft-boomerang` — the
mount list in `plugin-testing/.ddev/docker-compose.plugins.yaml` predates the rename, and the vendor
symlink still points there. Edit the canonical copy in `~/Sites/craft-boomerang/tests/shots/` and
copy it across before running:

```sh
cp ~/Sites/craft-boomerang/tests/shots/*.php ~/Sites/craft-rma/tests/shots/

# `craft-csr` fatals at class load on any CP page that enumerates element types, which is all of
# them. Off for the capture, back on afterwards.
docker exec -w /var/www/html ddev-plugin-testing-web php craft plugin/disable csr

docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/shots/seed.php
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-rma/tests/shots/urls.php

# Re-point the two detail URLs in specs/boomerang.json to what urls.php just printed, then:
cd ~/Sites/plugin-shots
CP_BASE=https://plugin-testing.ddev.site:33001 node capture.mjs out/boomerang specs/boomerang.json

docker exec -w /var/www/html ddev-plugin-testing-web php craft plugin/enable csr
```

`teardown.php` removes all of it again. It is exact rather than approximate: the seed tags
everything it makes — SKUs start `BMR-`, emails end `@boomerang.shots` — and nothing is deleted on
a looser match than that.

## Traps

**The harness is self-cleaning, so there is nothing to photograph.** `tests/integration/checks.php`
removes its own orders, returns and credit in a `finally`, which is right for a test suite and
leaves the control panel showing seven states, eight reasons and no returns at all. This directory
exists because a screenshot needs the opposite of what a test needs.

**`ddev exec` times out mid-run; use `docker exec`.** Same reason as the integration suite — `ddev
exec` re-checks that the project is running first.

**The base URL carries a port.** This project is on `https://plugin-testing.ddev.site:33001`;
`capture.mjs` defaults to port 443, where the router answers 404. Pass `CP_BASE`.

**Element IDs change on every re-seed**, so the two detail URLs in `specs/boomerang.json` go stale
the moment the fixture is rebuilt. A stale detail URL does **not** fail the capture run: the page
404s, the frame falls back to the whole document, and the shot looks plausible until somebody reads
it. That is what `urls.php` is for.

**Notification events are written by a queue job, after the transition has returned** — and
therefore after the RMA was backdated. Without a queue drain and a second pass, the history panel
shows a fortnight of state changes followed by four emails sent this morning. `seed.php` runs
`Craft::$app->getQueue()->run()` and pulls anything still in the future back to the RMA's
`stateChangedAt`.

**One `Wallet::spend()` is several ledger entries** — one per lot it had to draw from, each keyed
with the lot appended to the caller's idempotency key. Backdating them by matching the bare key
updates nothing, silently, and the intent is invisible in the diff.

**Nine returns on nine separate days draws "returns per day" as a flat line** pinned to the top of
its own box, which reads as a broken chart rather than as a quiet fortnight. Four of the thirteen
RMAs deliberately share a day with another.

**One customer needs several lots at different expiries, and a spend against them.** The credit
screen's whole argument is that a balance is a stack of dated lots rather than one number; a
customer whose every lot says "Never", all issued the same afternoon, argues the opposite. Maya
carries four lots and a $42 spend, which draws the 12-day lot to zero and takes $12 from the next —
FIFO by expiry, proving itself in a screenshot.

**The returns index ships a first-run help pane in its sidebar.** At 1600px the sidebar column is
narrow enough that it sets one word per line. `specs/boomerang.json` hides `#sidebar .pane`.

**The RMA detail screen needs a 1920px viewport.** Its right-hand column carries the state machine
and the order and customer meta; at 1600 that column is ~180px and the state buttons render on top
of each other.

**`craft-penny` and `craft-lyfe` break element saves and order completion outright** while enabled.
Both are detached in-process at the top of `seed.php`, never persisted — the same block as
`tests/integration/checks.php`, for the same reasons.
