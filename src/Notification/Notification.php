<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Notification;

/**
 * A Przelewy24 notification whose "sign" has been verified.
 *
 * For transaction notifications only the identifiers are used: the transaction state itself
 * is always read again from the Przelewy24 API.
 */
final readonly class Notification
{
    public const TYPE_TRANSACTION = 'transaction';

    public const TYPE_REFUND = 'refund';

    /**
     * @param self::TYPE_* $type
     * @param string|null $refundStatus one of Refund::STATUS_COMPLETED, Refund::STATUS_REJECTED
     */
    public function __construct(
        public string $type,
        public string $sessionId,
        public int $orderId,
        public int $amount,
        public string $currency,
        public ?string $refundsUuid = null,
        public ?string $refundStatus = null,
    ) {
    }
}
