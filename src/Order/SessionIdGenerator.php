<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function is_scalar;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strtolower;
use function substr;

/**
 * Builds "sessionId" values: "<orderNumber>-<paymentId>-<12 hex chars of the payment request hash>".
 *
 * Przelewy24 requires sessionId to be unique, so every payment attempt gets its own value.
 * The payment ID can be read back from a notification without trusting anything else in it.
 */
final readonly class SessionIdGenerator implements SessionIdGeneratorInterface
{
    private const PATTERN = '/-(\d{1,18})-([0-9a-f]{12})$/';

    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string
    {
        $paymentId = $payment->getId();
        $hash = $paymentRequest->getId();
        if (!is_scalar($paymentId) || '' === (string) $paymentId || null === $hash) {
            throw new InvalidArgumentException('Payment and payment request must be persisted before registering a Przelewy24 transaction.');
        }

        $order = $payment->getOrder();
        $number = $order instanceof OrderInterface ? (string) $order->getNumber() : '';
        $number = preg_replace('/[^A-Za-z0-9_.]/', '', $number) ?? '';
        if ('' === $number) {
            $number = 'order';
        }

        return sprintf('%s-%d-%s', mb_substr($number, 0, 40), (int) $paymentId, substr(str_replace('-', '', strtolower($hash)), 0, 12));
    }

    public function extractPaymentId(string $sessionId): ?int
    {
        if (1 !== preg_match(self::PATTERN, $sessionId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
