<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\enums;

use Craft;

/**
 * What a state *means*, as opposed to what it is called.
 *
 * States are configurable — a store selling mattresses has an inspection step and a store selling
 * t-shirts does not — so nothing in the plugin may ask "is this state called Received". It asks
 * for the kind. Renaming "Approved" to "Authorised" is then a label change and not a bug.
 */
enum StateKind: string
{
    /** Asked for, nobody has decided yet. */
    case Requested = 'requested';

    /** Approved. The customer may send the goods back. */
    case Approved = 'approved';

    /** In transit, or being inspected — anything between approved and received. */
    case Awaiting = 'awaiting';

    /** The goods are physically back. This is the kind that may restock. */
    case Received = 'received';

    /** Finished, and the customer got something: money, credit or goods. */
    case Resolved = 'resolved';

    /** Finished, and the merchant said no. */
    case Rejected = 'rejected';

    /** Finished, and the customer changed their mind about returning. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => Craft::t('boomerang', 'Requested'),
            self::Approved => Craft::t('boomerang', 'Approved'),
            self::Awaiting => Craft::t('boomerang', 'Awaiting goods'),
            self::Received => Craft::t('boomerang', 'Received'),
            self::Resolved => Craft::t('boomerang', 'Resolved'),
            self::Rejected => Craft::t('boomerang', 'Rejected'),
            self::Cancelled => Craft::t('boomerang', 'Cancelled'),
        };
    }

    /**
     * Whether an RMA in a state of this kind is finished with.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected, self::Cancelled], true);
    }

    /**
     * Whether the customer may still cancel the request themselves.
     *
     * Once the goods are on their way back, cancelling is a conversation, not a button.
     */
    public function isCustomerCancellable(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }

    /**
     * The Craft CP status colour a state of this kind gets by default.
     */
    public function defaultColor(): string
    {
        return match ($this) {
            self::Requested => 'blue',
            self::Approved => 'turquoise',
            self::Awaiting => 'orange',
            self::Received => 'purple',
            self::Resolved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }

    /**
     * @return array<string, string> handle => label, for a CP dropdown.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
