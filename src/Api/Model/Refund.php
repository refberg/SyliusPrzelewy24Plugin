<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

use function in_array;

/**
 * Refund statuses stored by the plugin in the payment details, and the statuses of refund notifications and of the API.
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

    /** "status" of a refund listed by the API: the refund was completed. */
    public const API_STATUS_COMPLETED = 1;

    /** "status" of a refund listed by the API: the refund waits to be executed. */
    public const API_STATUS_PENDING = 2;

    /** "status" of a refund listed by the API: the refund waits for the Przelewy24 approval. */
    public const API_STATUS_AWAITING_APPROVAL = 3;

    /** "status" of a refund listed by the API: the refund was rejected. */
    public const API_STATUS_REJECTED = 4;

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

    /**
     * @return self::STATUS_*|null
     */
    public static function fromApiStatus(int $status): ?string
    {
        return match ($status) {
            self::API_STATUS_COMPLETED => self::STATUS_COMPLETED,
            self::API_STATUS_PENDING, self::API_STATUS_AWAITING_APPROVAL => self::STATUS_PENDING,
            self::API_STATUS_REJECTED => self::STATUS_REJECTED,
            default => null,
        };
    }
}
