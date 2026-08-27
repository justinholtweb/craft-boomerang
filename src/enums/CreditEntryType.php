<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\enums;

use Craft;

/**
 * What a signed movement against a credit lot was for.
 *
 * The ledger is the balance — there is no stored number to disagree with it — so every row has to
 * say why it exists well enough for a merchant to answer "where did my $40 go" from the screen.
 */
enum CreditEntryType: string
{
    /** Credit created. Positive. Always paired with the lot it opened. */
    case Issue = 'issue';

    /** Allocated against an authorization, not yet captured. Negative, reversible. */
    case Hold = 'hold';

    /** An authorization that was never captured, given back. Positive. */
    case ReleaseHold = 'releaseHold';

    /** Spent on an order. Negative. */
    case Spend = 'spend';

    /** A wallet payment refunded, back into the lot it came from. Positive. */
    case Refund = 'refund';

    /** Time ran out. Negative, written by `boomerang/credit/expire`. */
    case Expire = 'expire';

    /** A human moved the number, and said why. Either sign. */
    case Adjust = 'adjust';

    /** Credit withdrawn — a fraudulent return, a reversed decision. Negative. */
    case Revoke = 'revoke';

    public function label(): string
    {
        return match ($this) {
            self::Issue => Craft::t('boomerang', 'Issued'),
            self::Hold => Craft::t('boomerang', 'Held'),
            self::ReleaseHold => Craft::t('boomerang', 'Hold released'),
            self::Spend => Craft::t('boomerang', 'Spent'),
            self::Refund => Craft::t('boomerang', 'Refunded'),
            self::Expire => Craft::t('boomerang', 'Expired'),
            self::Adjust => Craft::t('boomerang', 'Adjusted'),
            self::Revoke => Craft::t('boomerang', 'Revoked'),
        };
    }

    /**
     * Whether an entry of this type reduces what the customer can spend.
     */
    public function isDebit(): bool
    {
        return in_array($this, [self::Hold, self::Spend, self::Expire, self::Revoke], true);
    }
}
