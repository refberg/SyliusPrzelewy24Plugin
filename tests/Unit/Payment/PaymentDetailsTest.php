<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Component\Core\Model\Payment;

final class PaymentDetailsTest extends TestCase
{
    public function test_data_is_kept_under_the_plugin_key_next_to_other_details(): void
    {
        $payment = new Payment();
        $payment->setDetails(['other' => 'kept']);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('s-1', 'TOKEN', 1500, 'PLN');
        $details->save();

        self::assertSame('kept', $payment->getDetails()['other']);
        self::assertSame(['transactions' => ['s-1' => ['token' => 'TOKEN', 'status' => 0, 'amount' => 1500, 'currency' => 'PLN']], 'current_session_id' => 's-1'], $payment->getDetails()[Przelewy24Gateway::DETAILS_KEY]);
    }

    public function test_corrupted_details_are_read_as_empty(): void
    {
        $payment = new Payment();
        $payment->setDetails([Przelewy24Gateway::DETAILS_KEY => ['transactions' => 'x', 'refunds' => ['a' => 'b'], 'warnings' => [1, ['type' => 't']], 'current_session_id' => 5]]);
        $details = PaymentDetails::of($payment);

        self::assertFalse($details->hasTransaction('x'));
        self::assertSame([], $details->refunds());
        self::assertSame([['type' => 't']], $details->warnings());
        self::assertNull($details->getCurrentSessionId());
        self::assertSame([], $details->getOpenSessionIds());
    }

    public function test_open_transactions_exclude_final_and_cancelled_ones_newest_first(): void
    {
        $details = PaymentDetails::of(new Payment());
        foreach (['s-1', 's-2', 's-3', 's-4'] as $sessionId) {
            $details->addTransaction($sessionId, 'T', 1500, 'PLN');
        }
        $details->recordTransaction(new Transaction('s-2', 1, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
        $details->recordTransaction(new Transaction('s-3', 2, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN'));
        $details->cancelTransaction('s-4');

        self::assertSame(['s-3', 's-1'], $details->getOpenSessionIds());
        self::assertTrue($details->isCancelled('s-4'));
        self::assertSame('s-4', $details->getCurrentSessionId());
    }

    public function test_unknown_transactions_are_not_recorded(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->recordTransaction(new Transaction('s-9', 1, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
        $details->cancelTransaction('s-9');

        self::assertFalse($details->hasTransaction('s-9'));
        self::assertNull($details->getTransactionStatus('s-9'));
    }

    public function test_warnings_are_recorded_once(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, 's-1');
        $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, 's-1');
        $details->addWarning(PaymentDetails::WARNING_LATE_PAYMENT, 's-1');

        self::assertCount(2, $details->warnings());
    }

    public function test_refund_amounts_count_own_and_external_refunds(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->recordRefund('own', 'request-1', 500, 'PLN');
        self::assertTrue($details->recordExternalRefund('panel-1', Refund::STATUS_COMPLETED, 300, 'PLN'));
        self::assertFalse($details->recordExternalRefund('panel-1', Refund::STATUS_COMPLETED, 300, 'PLN'));
        self::assertTrue($details->recordExternalRefund('panel-2', Refund::STATUS_REJECTED, 700, 'PLN'));

        self::assertSame(300, $details->completedRefundAmount());
        self::assertSame(800, $details->activeRefundAmount());
        self::assertTrue($details->hasActiveRefund());

        self::assertTrue($details->updateRefundStatus('own', Refund::STATUS_REJECTED));
        self::assertFalse($details->hasActiveRefund());
        self::assertSame(300, $details->activeRefundAmount());

        // Final statuses are never overwritten; unknown refunds are reported.
        self::assertTrue($details->updateRefundStatus('own', Refund::STATUS_COMPLETED));
        self::assertSame(Refund::STATUS_REJECTED, $details->refunds()['own']['status'] ?? null);
        self::assertFalse($details->updateRefundStatus('unknown', Refund::STATUS_COMPLETED));
    }

    public function test_external_refunds_are_flagged(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->recordExternalRefund('panel-1', Refund::STATUS_COMPLETED, 300, 'PLN');

        self::assertTrue($details->refunds()['panel-1']['external'] ?? null);
        self::assertFalse($details->hasActiveRefund());
    }
}
