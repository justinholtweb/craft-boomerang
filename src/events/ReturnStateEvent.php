<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\events;

use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\models\State;
use craft\events\CancelableEvent;

/**
 * Raised around an RMA changing state.
 *
 * A `CancelableEvent`, not a plain `yii\base\Event` — the latter has `handled`, not `isValid`,
 * and setting `isValid` on one throws rather than refusing anything.
 *
 * `isValid` is honoured only on the *before* event, and setting it false refuses the transition
 * outright — the RMA is left exactly where it was. That is the extension point for a store whose
 * finance team has to sign off anything over a certain amount.
 */
class ReturnStateEvent extends CancelableEvent
{
    public ReturnRequest $return;
    public ?State $fromState = null;
    public State $toState;

    /** Whether the customer made this happen from the portal. */
    public bool $byCustomer = false;
}
