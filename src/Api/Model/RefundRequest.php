<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

/**
 * Body of POST /api/v1/transaction/refund for a single refund.
 */
final readonly class RefundRequest
{
    /**
     * @param string $requestId unique request ID (max. 45 characters)
     * @param string $refundsUuid unique refund ID in the shop (max. 35 characters), returned in the refund notification
     * @param string $description shown in the transfer title (max. 35 characters)
     */
    public function __construct(
        public string $requestId,
        public string $refundsUuid,
        public int $orderId,
        public string $sessionId,
        public int $amount,
        public string $description,
        public string $urlStatus,
    ) {
    }
}
