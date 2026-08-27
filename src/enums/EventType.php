<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\enums;

use Craft;

/**
 * The RMA's history, one row per thing that happened.
 *
 * Written only from {@see \justinholtweb\boomerang\services\Returns} and the services it calls, so
 * the log is a record of what the plugin actually did rather than of what a controller intended.
 */
enum EventType: string
{
    case Created = 'created';
    case StateChanged = 'stateChanged';
    case Note = 'note';
    case ItemsChanged = 'itemsChanged';
    case Restocked = 'restocked';
    case Refunded = 'refunded';
    case CreditIssued = 'creditIssued';
    case ExchangeCreated = 'exchangeCreated';
    case LabelCreated = 'labelCreated';
    case LabelVoided = 'labelVoided';
    case NotificationSent = 'notificationSent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => Craft::t('boomerang', 'Return requested'),
            self::StateChanged => Craft::t('boomerang', 'State changed'),
            self::Note => Craft::t('boomerang', 'Note'),
            self::ItemsChanged => Craft::t('boomerang', 'Items changed'),
            self::Restocked => Craft::t('boomerang', 'Restocked'),
            self::Refunded => Craft::t('boomerang', 'Refunded'),
            self::CreditIssued => Craft::t('boomerang', 'Store credit issued'),
            self::ExchangeCreated => Craft::t('boomerang', 'Exchange order created'),
            self::LabelCreated => Craft::t('boomerang', 'Return label created'),
            self::LabelVoided => Craft::t('boomerang', 'Return label voided'),
            self::NotificationSent => Craft::t('boomerang', 'Notification sent'),
            self::Failed => Craft::t('boomerang', 'Failed'),
        };
    }

    /**
     * Events a customer may see on the portal status page.
     *
     * Staff notes and failures are deliberately not on this list — a customer reading "Refund
     * failed: gateway declined" learns only that something is wrong and nothing about what to do.
     */
    public function isCustomerVisible(): bool
    {
        return in_array($this, [
            self::Created,
            self::StateChanged,
            self::Refunded,
            self::CreditIssued,
            self::ExchangeCreated,
            self::LabelCreated,
        ], true);
    }
}
