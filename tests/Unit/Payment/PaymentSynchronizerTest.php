<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24Client;
use Refberg\SyliusPrzelewy24Plugin\Config\Environment;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidator;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplier;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizer;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;

final class PaymentSynchronizerTest extends TestCase
{
    private FakePrzelewy24 $p24;

    private Przelewy24Client $client;

    protected function setUp(): void
    {
        $this->p24 = new FakePrzelewy24();
        $this->client = new Przelewy24Client(new MockHttpClient($this->p24, 'https://' . FakePrzelewy24::HOST), new SignatureVerifier());
    }

    public function test_paid_transaction_is_verified_before_the_payment_is_completed(): void
    {
        $payment = $this->registeredPayment('s-1');
        $orderId = $this->p24->pay('s-1');

        self::assertSame(PaymentStateApplierInterface::RESULT_COMPLETED, $this->synchronizer()->synchronize($payment, 's-1', self::credentials(), $orderId));

        self::assertCount(1, $this->p24->requests('PUT'));
        self::assertSame(Transaction::STATUS_PAYMENT_MADE, $this->p24->transaction('s-1')['status']);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function test_paid_transaction_that_cannot_complete_the_payment_is_not_verified(): void
    {
        $payment = $this->registeredPayment('s-1');
        $payment->setState(PaymentInterface::STATE_CANCELLED);
        $this->p24->pay('s-1');

        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $this->synchronizer()->synchronize($payment, 's-1', self::credentials()));

        self::assertSame([], $this->p24->requests('PUT'));
        self::assertSame(Transaction::STATUS_ADVANCE_PAYMENT, $this->p24->transaction('s-1')['status']);
    }

    public function test_mismatching_transaction_is_neither_verified_nor_applied(): void
    {
        $payment = $this->registeredPayment('s-1');
        $orderId = $this->p24->pay('s-1');

        self::assertSame(PaymentSynchronizerInterface::RESULT_INVALID, $this->synchronizer()->synchronize($payment, 's-1', self::credentials(), $orderId + 1));

        self::assertSame([], $this->p24->requests('PUT'));
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame(Transaction::STATUS_NO_PAYMENT, PaymentDetails::of($payment)->getTransactionStatus('s-1'));
    }

    private function synchronizer(): PaymentSynchronizer
    {
        return new PaymentSynchronizer($this->client, new TransactionValidator(), new PaymentStateApplier(new PaymentStateMachine()));
    }

    private function registeredPayment(string $sessionId): Payment
    {
        $this->client->registerTransaction(self::credentials(), [
            'sessionId' => $sessionId, 'amount' => 1500, 'currency' => 'PLN', 'description' => 'Order', 'email' => 'buyer@example.com',
            'country' => 'PL', 'language' => 'pl', 'urlReturn' => 'https://shop.example/return',
        ]);

        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        $details->addTransaction($sessionId, 'TOKEN', 1500, 'PLN');
        $details->save();

        return $payment;
    }

    private static function credentials(): Przelewy24Credentials
    {
        return new Przelewy24Credentials(FakePrzelewy24::MERCHANT_ID, FakePrzelewy24::POS_ID, FakePrzelewy24::CRC_KEY, FakePrzelewy24::API_KEY, Environment::Sandbox);
    }
}
