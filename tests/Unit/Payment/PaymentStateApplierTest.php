<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Payment;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplier;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\MemoryLogger;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;
use function substr;

final class PaymentStateApplierTest extends TestCase
{
    private PaymentStateMachine $stateMachine;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->stateMachine = new PaymentStateMachine();
        $this->logger = new MemoryLogger();
    }

    public function test_verified_transaction_completes_the_payment_once(): void
    {
        $payment = self::payment();
        $applier = $this->applier();

        self::assertSame(PaymentStateApplierInterface::RESULT_COMPLETED, $applier->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE)));
        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $applier->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE)));

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
        $details = PaymentDetails::of($payment);
        self::assertSame('s-1', $details->getCompletedSessionId());
        self::assertSame(3000000001, $details->getOrderId('s-1'));
        self::assertSame(Transaction::STATUS_PAYMENT_MADE, $details->getTransactionStatus('s-1'));
    }

    #[TestWith([Transaction::STATUS_NO_PAYMENT])]
    #[TestWith([Transaction::STATUS_PAYMENT_RETURNED])]
    public function test_unpaid_or_returned_transaction_never_changes_the_payment(int $status): void
    {
        $payment = self::payment();

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::tx('s-1', $status)));

        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame([], $this->stateMachine->applied);
    }

    public function test_second_paid_transaction_is_a_duplicate_warning(): void
    {
        $payment = self::payment('s-2');
        $applier = $this->applier();
        $applier->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE));

        self::assertFalse($applier->canComplete($payment, self::tx('s-2', Transaction::STATUS_ADVANCE_PAYMENT)));
        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $applier->apply($payment, self::tx('s-2', Transaction::STATUS_ADVANCE_PAYMENT)));
        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $applier->apply($payment, self::tx('s-2', Transaction::STATUS_ADVANCE_PAYMENT)));

        $details = PaymentDetails::of($payment);
        self::assertSame('s-1', $details->getCompletedSessionId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'session_id' => 's-2']], $details->warnings());
        self::assertSame('error', $this->logger->records[1]['level'] ?? null);
    }

    public function test_payment_that_cannot_be_completed_gets_a_late_payment_warning(): void
    {
        $payment = self::payment();
        $payment->setState(PaymentInterface::STATE_CANCELLED);
        $applier = $this->applier();

        self::assertFalse($applier->canComplete($payment, self::tx('s-1', Transaction::STATUS_ADVANCE_PAYMENT)));
        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $applier->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE)));

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([['type' => PaymentDetails::WARNING_LATE_PAYMENT, 'session_id' => 's-1']], PaymentDetails::of($payment)->warnings());
    }

    public function test_cancelled_transaction_cannot_complete_the_payment(): void
    {
        $payment = self::payment();
        $details = PaymentDetails::of($payment);
        $details->cancelTransaction('s-1');
        $details->save();

        self::assertFalse($this->applier()->canComplete($payment, self::tx('s-1', Transaction::STATUS_ADVANCE_PAYMENT)));
        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $this->applier()->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE)));
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_unverified_paid_transaction_never_completes_the_payment(): void
    {
        $payment = self::payment();
        $applier = $this->applier();

        self::assertTrue($applier->canComplete($payment, self::tx('s-1', Transaction::STATUS_ADVANCE_PAYMENT)));
        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $applier->apply($payment, self::tx('s-1', Transaction::STATUS_ADVANCE_PAYMENT)));

        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_status_is_never_moved_back(): void
    {
        $payment = self::payment();
        $applier = $this->applier();
        $applier->apply($payment, self::tx('s-1', Transaction::STATUS_PAYMENT_MADE));

        $applier->apply($payment, self::tx('s-1', Transaction::STATUS_NO_PAYMENT));

        self::assertSame(Transaction::STATUS_PAYMENT_MADE, PaymentDetails::of($payment)->getTransactionStatus('s-1'));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    private function applier(): PaymentStateApplier
    {
        return new PaymentStateApplier($this->stateMachine, $this->logger);
    }

    private static function tx(string $sessionId, int $status): Transaction
    {
        return new Transaction($sessionId, Transaction::STATUS_NO_PAYMENT === $status ? null : 3000000000 + (int) substr($sessionId, 2), $status, 1500, 'PLN');
    }

    private static function payment(string ...$moreSessionIds): Payment
    {
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        foreach (['s-1', ...$moreSessionIds] as $sessionId) {
            $details->addTransaction($sessionId, 'TOKEN', 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }
}
