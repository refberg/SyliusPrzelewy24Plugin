<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

interface TransactionRequestFactoryInterface
{
    /**
     * Body of POST /api/v1/transaction/register without merchantId, posId and sign (added by the API client).
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function create(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $sessionId): array;
}
