<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use DateTime;

/**
 * A quantity of store credit as it was issued, with its own expiry.
 *
 * ## Why lots rather than one running balance
 *
 * Because credit expires. A single signed ledger cannot answer "how much of this customer's $60
 * expires at the end of the month" without re-deriving which dollars came from which issue, and
 * getting that wrong means either expiring money the customer still owns or letting them spend
 * money that lapsed. A lot knows what it was worth, what is left of it, and when it dies; spending
 * consumes lots in expiry order, so the money that is about to lapse is always the money that gets
 * spent first — which is both correct and the thing a customer would choose.
 *
 * `amountRemaining` is a cache of the entries against this lot and is only ever written inside the
 * same transaction that writes them, under a row lock. It exists so that a balance is one indexed
 * sum rather than a group-by over every movement the customer has ever made.
 */
class CreditLot extends Model
{
    public ?int $id = null;
    public ?int $customerId = null;
    public ?int $storeId = null;
    public string $currency = 'USD';

    public float $amountIssued = 0.0;
    public float $amountRemaining = 0.0;

    /** Null never expires. */
    public ?DateTime $expiresAt = null;

    /** The RMA that produced it, when it came from one. */
    public ?int $returnId = null;

    /** Who issued it, when a human did. */
    public ?int $issuedBy = null;

    public ?string $note = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['expiresAt']);
    }

    public function getIsExpired(?DateTime $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new DateTime());
    }

    public function getIsSpent(): bool
    {
        return $this->amountRemaining <= 0.0;
    }

    /**
     * What can still be drawn from this lot right now.
     */
    public function getSpendable(?DateTime $now = null): float
    {
        return $this->getIsExpired($now) ? 0.0 : max(0.0, $this->amountRemaining);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['customerId', 'currency'], 'required'];
        $rules[] = [['amountIssued'], 'number', 'min' => 0.01];
        $rules[] = [['amountRemaining'], 'number', 'min' => 0];
        $rules[] = [['currency'], 'string', 'max' => 3];
        $rules[] = [['note'], 'string'];

        return $rules;
    }
}
