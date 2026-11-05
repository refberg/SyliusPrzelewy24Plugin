<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Checks that a transaction read from the Przelewy24 API really belongs to the given Sylius payment.
 *
 * The shop is checked by the API call itself: transactions are read with the credentials of the
 * payment method, and the API returns only transactions of that shop.
 */
final readonly class TransactionValidator implements TransactionValidatorInterface
{
    public function validate(Transaction $transaction, PaymentInterface $payment, ?int $expectedOrderId = null): array
    {
        $details = PaymentDetails::of($payment);
        $errors = [];

        if (!$details->hasTransaction($transaction->sessionId)) {
            $errors[] = 'Przelewy24 transaction is not linked to this payment';
        }

        if ($transaction->amount !== $payment->getAmount()) {
            $errors[] = 'amount does not match';
        }

        if ($transaction->currency !== $payment->getCurrencyCode()) {
            $errors[] = 'currency does not match';
        }

        if (null !== $expectedOrderId && $transaction->orderId !== $expectedOrderId) {
            $errors[] = 'orderId does not match';
        }

        $knownOrderId = $details->getOrderId($transaction->sessionId);
        if (null !== $knownOrderId && null !== $transaction->orderId && $knownOrderId !== $transaction->orderId) {
            $errors[] = 'orderId differs from the recorded one';
        }

        if ($transaction->isPaid() && null === $transaction->orderId) {
            $errors[] = 'paid transaction has no orderId';
        }

        return $errors;
    }
}
