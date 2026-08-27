<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use DateTime;
use justinholtweb\boomerang\enums\CreditEntryType;

/**
 * One signed movement against a credit lot.
 *
 * `idempotencyKey` is unique across the table and is the reason a retried checkout, a
 * double-clicked "Issue credit" button and a queue job that runs twice all move the money once.
 * Every caller has to supply one; there is no "just this once" overload, because the one place
 * that would get used is the one place it matters.
 */
class CreditEntry extends Model
{
    public ?int $id = null;
    public ?int $lotId = null;
    public ?int $customerId = null;
    public ?int $storeId = null;
    public string $currency = 'USD';

    public string $type = CreditEntryType::Issue->value;

    /** Signed: positive gives the customer money, negative takes it. */
    public float $amount = 0.0;

    public ?int $orderId = null;
    public ?int $transactionId = null;

    /** Commerce's own hash for the transaction, which exists before its ID does. */
    public ?string $transactionHash = null;
    public ?int $returnId = null;
    public ?int $userId = null;

    public string $idempotencyKey = '';
    public ?string $note = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getType(): CreditEntryType
    {
        return CreditEntryType::tryFrom($this->type) ?? CreditEntryType::Adjust;
    }

    public function getIsDebit(): bool
    {
        return $this->amount < 0;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['type', 'idempotencyKey', 'currency'], 'required'];
        $rules[] = [['amount'], 'number'];
        $rules[] = [['type'], 'in', 'range' => array_column(CreditEntryType::cases(), 'value')];
        $rules[] = [['idempotencyKey'], 'string', 'max' => 255];
        $rules[] = [['currency'], 'string', 'max' => 3];
        $rules[] = [['note'], 'string'];

        return $rules;
    }
}
