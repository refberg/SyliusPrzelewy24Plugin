<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Order;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidator;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\Payment;

final class TransactionValidatorTest extends TestCase
{
    public function test_matching_transaction_has_no_errors(): void
    {
        self::assertSame([], (new TransactionValidator())->validate(self::paid(), self::payment(), 3000000001));
        self::assertSame([], (new TransactionValidator())->validate(new Transaction('s-1', null, Transaction::STATUS_NO_PAYMENT, 1500, 'PLN'), self::payment()));
    }

    public function test_every_mismatch_is_reported(): void
    {
        $errors = (new TransactionValidator())->validate(new Transaction('s-2', 5, Transaction::STATUS_PAYMENT_MADE, 1, 'EUR'), self::payment(), 3000000001);

        self::assertSame([
            'Przelewy24 transaction is not linked to this payment',
            'amount does not match',
            'currency does not match',
            'orderId does not match',
        ], $errors);
    }

    public function test_order_id_must_match_the_recorded_one(): void
    {
        $payment = self::payment();
        $details = PaymentDetails::of($payment);
        $details->recordTransaction(self::paid());
        $details->save();

        self::assertSame(['orderId differs from the recorded one'], (new TransactionValidator())->validate(new Transaction('s-1', 99, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'), $payment));
    }

    public function test_paid_transaction_needs_an_order_id(): void
    {
        self::assertSame(['paid transaction has no orderId'], (new TransactionValidator())->validate(new Transaction('s-1', null, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN'), self::payment()));
    }

    private static function paid(): Transaction
    {
        return new Transaction('s-1', 3000000001, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN');
    }

    private static function payment(): Payment
    {
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $details = PaymentDetails::of($payment);
        $details->addTransaction('s-1', 'TOKEN', 1500, 'PLN');
        $details->save();

        return $payment;
    }
}
