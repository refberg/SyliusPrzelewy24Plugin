<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Builds the Przelewy24 "sessionId" (the shop's ID of a transaction, at most 100 characters).
 *
 * extractPaymentId() must read the payment ID back from any value returned by generate(): notifications are matched to payments by it.
 */
interface SessionIdGeneratorInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string;

    public function extractPaymentId(string $sessionId): ?int;
}
