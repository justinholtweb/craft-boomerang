<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use DateTime;

/**
 * Whether an order can be returned, and which of it.
 *
 * The single verdict every caller reads — the portal, the CP, the console and the front-end API —
 * so the reason a customer is refused is exactly the reason the merchant sees on the order. It
 * carries a per-line trace rather than a bare boolean for the same reason Free Ride's matcher
 * does: "not eligible" is not an answer anybody can act on.
 */
class Eligibility extends Model
{
    public ?int $orderId = null;

    /** Whether anything at all on this order can be returned. */
    public bool $isEligible = false;

    /** Order-level refusals, in words: outside the window, not paid, wrong status. */
    public array $messages = [];

    /** Machine-readable forms of the same, e.g. `windowClosed`, `notCompleted`. */
    public array $codes = [];

    /** @var EligibleLine[] Keyed by line item ID. */
    public array $lines = [];

    /** The last day a return can be started, when a window applies. */
    public ?DateTime $windowClosesAt = null;

    /** Days left in the window. Negative once it has closed, null when there is no window. */
    public ?int $daysRemaining = null;

    private ?Order $_order = null;

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
        $this->orderId = $order?->id;
    }

    public function getOrder(): ?Order
    {
        return $this->_order;
    }

    /**
     * The lines the customer may actually pick from.
     *
     * @return EligibleLine[]
     */
    public function getEligibleLines(): array
    {
        return array_values(array_filter($this->lines, fn(EligibleLine $line) => $line->isEligible && $line->qtyAvailable > 0));
    }

    /**
     * @return EligibleLine[]
     */
    public function getIneligibleLines(): array
    {
        return array_values(array_filter($this->lines, fn(EligibleLine $line) => !$line->isEligible || $line->qtyAvailable < 1));
    }

    public function getLine(int $lineItemId): ?EligibleLine
    {
        return $this->lines[$lineItemId] ?? null;
    }

    /**
     * How many of a given line the customer may still ask to send back.
     */
    public function availableQty(int $lineItemId): int
    {
        $line = $this->getLine($lineItemId);

        return $line !== null && $line->isEligible ? $line->qtyAvailable : 0;
    }

    /**
     * The first refusal, for a UI with room for one sentence.
     */
    public function getFirstMessage(): ?string
    {
        return $this->messages[0] ?? null;
    }

    public function hasCode(string $code): bool
    {
        return in_array($code, $this->codes, true);
    }

    /**
     * Everything that was decided, flattened for a log line or a console table.
     *
     * @return array<int, array{scope: string, subject: string, eligible: bool, message: string}>
     */
    public function getTrace(): array
    {
        $trace = [];

        foreach ($this->messages as $i => $message) {
            $trace[] = [
                'scope' => 'order',
                'subject' => $this->codes[$i] ?? 'order',
                'eligible' => false,
                'message' => $message,
            ];
        }

        foreach ($this->lines as $line) {
            $trace[] = [
                'scope' => 'line',
                'subject' => $line->sku !== '' ? $line->sku : (string)$line->lineItemId,
                'eligible' => $line->isEligible && $line->qtyAvailable > 0,
                'message' => $line->message ?? sprintf('%d of %d available', $line->qtyAvailable, $line->qtyOrdered),
            ];
        }

        return $trace;
    }
}
