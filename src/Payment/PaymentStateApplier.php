<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * The only place where the plugin changes the state of a Sylius payment.
 *
 * Rules are monotonic, so duplicated or out-of-order notifications cannot change the outcome:
 *  - a verified transaction ("payment made") completes the payment once; a second paid transaction
 *    of the same payment, or a payment that can no longer be completed (cancelled, failed), is recorded
 *    as a warning and never pays the order twice,
 *  - a paid but unverified transaction ("advance payment") reaches this class only when it cannot complete
 *    the payment, so it is recorded as a warning as well,
 *  - "no payment" and "payment returned" never change the payment state.
 *
 * Przelewy24 does not report failed payments, so a payment is never failed here.
 */
final readonly class PaymentStateApplier implements PaymentStateApplierInterface
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function canComplete(PaymentInterface $payment, Transaction $transaction): bool
    {
        $details = PaymentDetails::of($payment);

        return null === $details->getCompletedSessionId() &&
            !$details->isCancelled($transaction->sessionId) &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
    }

    public function apply(PaymentInterface $payment, Transaction $transaction): string
    {
        $details = PaymentDetails::of($payment);

        $result = match ($transaction->status) {
            Transaction::STATUS_PAYMENT_MADE, Transaction::STATUS_ADVANCE_PAYMENT => $this->onPaid($payment, $details, $transaction),
            default => self::RESULT_UNCHANGED,
        };

        // The status is recorded after the decision, so canComplete() sees the state before this transaction.
        $details->recordTransaction($transaction);
        $details->save();

        return $result;
    }

    private function onPaid(PaymentInterface $payment, PaymentDetails $details, Transaction $transaction): string
    {
        $completedSessionId = $details->getCompletedSessionId();
        if ($completedSessionId === $transaction->sessionId) {
            return self::RESULT_UNCHANGED;
        }

        if (Transaction::STATUS_PAYMENT_MADE === $transaction->status && $this->canComplete($payment, $transaction)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $details->setCompletedSessionId($transaction->sessionId);

            $this->logger->info('Przelewy24 payment completed.', ['payment_id' => $payment->getId(), 'session_id' => $transaction->sessionId]);

            return self::RESULT_COMPLETED;
        }

        $warning = null !== $completedSessionId ? PaymentDetails::WARNING_DUPLICATE_PAYMENT : PaymentDetails::WARNING_LATE_PAYMENT;
        $details->addWarning($warning, $transaction->sessionId);

        $this->logger->error(
            Transaction::STATUS_ADVANCE_PAYMENT === $transaction->status
                ? 'Przelewy24 transaction was paid, but the Sylius payment cannot be completed; the transaction was not verified, so Przelewy24 keeps it as an advance payment of the customer.'
                : 'Przelewy24 transaction was paid and verified, but the Sylius payment cannot be completed. Manual refund may be needed.',
            ['payment_id' => $payment->getId(), 'payment_state' => $payment->getState(), 'session_id' => $transaction->sessionId, 'reason' => $warning],
        );

        return self::RESULT_WARNING;
    }
}
