<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models\responses;

use craft\commerce\base\RequestResponseInterface;

/**
 * What the store-credit gateway hands back to Commerce.
 *
 * There is no remote gateway, so the "reference" is the wallet's own idempotency key. That is the
 * useful thing to put there: it is what a merchant looking at a transaction on an order needs in
 * order to find the matching rows in the credit ledger.
 */
class CreditResponse implements RequestResponseInterface
{
    public function __construct(
        private readonly bool $successful = true,
        private readonly string $reference = '',
        private readonly string $message = '',
        private readonly array $data = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return false;
    }

    public function getRedirectMethod(): string
    {
        return '';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        return $this->reference;
    }

    public function getCode(): string
    {
        return '';
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function redirect(): void
    {
    }
}
