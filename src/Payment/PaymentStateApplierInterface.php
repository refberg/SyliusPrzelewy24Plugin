<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Payment;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Input must be a transaction that was read from the Przelewy24 API and validated against the payment.
 * Rules must be monotonic, so duplicated or out-of-order notifications cannot change the outcome
 * and the order is never paid twice.
 */
interface PaymentStateApplierInterface
{
    public const RESULT_COMPLETED = 'completed';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_WARNING = 'warning';

    /**
     * Whether a paid transaction would complete the payment. A transaction that cannot complete it is not verified.
     */
    public function canComplete(PaymentInterface $payment, Transaction $transaction): bool;

    /**
     * @return string one of self::RESULT_*
     */
    public function apply(PaymentInterface $payment, Transaction $transaction): string;
}
