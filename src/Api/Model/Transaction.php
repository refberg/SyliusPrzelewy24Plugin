<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

use function in_array;

/**
 * Transaction state as returned by GET /api/v1/transaction/by/sessionId/{sessionId}.
 */
final readonly class Transaction
{
    /** No payment yet (the transaction is registered, the customer has not paid). */
    public const STATUS_NO_PAYMENT = 0;

    /**
     * Paid by the customer but not verified by the merchant (transaction/verify): Przelewy24 holds the money
     * as an advance payment and does not settle it with the merchant.
     */
    public const STATUS_ADVANCE_PAYMENT = 1;

    /** Paid and verified. */
    public const STATUS_PAYMENT_MADE = 2;

    /** Paid and returned to the customer. */
    public const STATUS_PAYMENT_RETURNED = 3;

    public function __construct(
        public string $sessionId,
        public ?int $orderId,
        public int $status,
        public int $amount,
        public string $currency,
    ) {
    }

    public function withStatus(int $status): self
    {
        return new self($this->sessionId, $this->orderId, $status, $this->amount, $this->currency);
    }

    /**
     * The customer paid the transaction (verified or not).
     */
    public function isPaid(): bool
    {
        return self::isPaidStatus($this->status);
    }

    public static function isPaidStatus(?int $status): bool
    {
        return in_array($status, [self::STATUS_ADVANCE_PAYMENT, self::STATUS_PAYMENT_MADE, self::STATUS_PAYMENT_RETURNED], true);
    }

    /**
     * No further payment is expected for a transaction in this status.
     */
    public static function isFinalStatus(?int $status): bool
    {
        return in_array($status, [self::STATUS_PAYMENT_MADE, self::STATUS_PAYMENT_RETURNED], true);
    }
}
