<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

use function in_array;

/**
 * Refund statuses stored by the plugin in the payment details, and the statuses of refund notifications.
 */
final class Refund
{
    /** Requested by Sylius and accepted by Przelewy24; the final status arrives in a refund notification. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    /** "status" of a refund notification: the refund was completed. */
    public const NOTIFICATION_STATUS_COMPLETED = 0;

    /** "status" of a refund notification: the refund was rejected. */
    public const NOTIFICATION_STATUS_REJECTED = 1;

    private function __construct()
    {
    }

    public static function isFinalStatus(?string $status): bool
    {
        return in_array($status, [self::STATUS_COMPLETED, self::STATUS_REJECTED], true);
    }

    /**
     * @return self::STATUS_COMPLETED|self::STATUS_REJECTED|null
     */
    public static function fromNotificationStatus(int $status): ?string
    {
        return match ($status) {
            self::NOTIFICATION_STATUS_COMPLETED => self::STATUS_COMPLETED,
            self::NOTIFICATION_STATUS_REJECTED => self::STATUS_REJECTED,
            default => null,
        };
    }
}
