<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\enums;

use Craft;

/**
 * What the customer gets back.
 *
 * Chosen per RMA and carried out once, on entry to a state of kind {@see StateKind::Resolved}.
 */
enum Resolution: string
{
    /** Money, back the way it came, through Commerce's own refund. */
    case Refund = 'refund';

    /** Store credit in the wallet. Pro. */
    case Credit = 'credit';

    /** A new order for different goods, credited with what came back. Pro. */
    case Exchange = 'exchange';

    /** The same goods again, at a zero balance. Pro. */
    case Replacement = 'replacement';

    /** Nothing automatic — the merchant handled it outside Craft, and says so. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Refund => Craft::t('boomerang', 'Refund'),
            self::Credit => Craft::t('boomerang', 'Store credit'),
            self::Exchange => Craft::t('boomerang', 'Exchange'),
            self::Replacement => Craft::t('boomerang', 'Replacement'),
            self::None => Craft::t('boomerang', 'No automatic resolution'),
        };
    }

    /**
     * Whether this resolution is only available to Pro.
     */
    public function isPro(): bool
    {
        return in_array($this, [self::Credit, self::Exchange, self::Replacement], true);
    }

    /**
     * Whether carrying this out moves money or goods, and therefore must not be attempted twice.
     */
    public function isTransacting(): bool
    {
        return $this !== self::None;
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $proOnly = true): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if (!$proOnly && $case->isPro()) {
                continue;
            }

            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
