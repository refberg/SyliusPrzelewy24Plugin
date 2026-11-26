<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

/**
 * A refund as listed by GET /api/v1/refund/by/orderId/{orderId}.
 */
final readonly class RefundListed
{
    /**
     * @param Refund::STATUS_* $status
     */
    public function __construct(
        public string $requestId,
        public string $status,
        public int $amount,
        public string $currency,
    ) {
    }
}
