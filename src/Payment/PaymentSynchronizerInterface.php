<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Payment;

use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24Exception;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentSynchronizerInterface
{
    public const RESULT_INVALID = 'invalid';

    /**
     * @param int|null $notifiedOrderId Przelewy24 orderId from a signed notification, when there is one
     *
     * @throws Przelewy24Exception
     *
     * @return string one of PaymentStateApplierInterface::RESULT_* or self::RESULT_INVALID
     */
    public function synchronize(PaymentInterface $payment, string $sessionId, Przelewy24Credentials $credentials, ?int $notifiedOrderId = null): string;
}
