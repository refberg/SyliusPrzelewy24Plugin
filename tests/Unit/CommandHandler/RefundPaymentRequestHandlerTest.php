<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Command\RefundPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;
use function array_keys;

final class RefundPaymentRequestHandlerTest extends TestCase
{
    private const HASH = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    public function test_request_that_is_not_new_is_left_alone(): void
    {
        $payment = self::payment();
        $paymentRequest = self::refundRequest($payment);
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);

        $this->handle($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState());
        self::assertSame([], $paymentRequest->getResponseData());
    }

    public function test_payment_not_completed_through_przelewy24_fails_without_calling_the_api(): void
    {
        $paymentRequest = self::refundRequest(self::payment());

        $this->handle($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'No completed Przelewy24 transaction for this payment.'], $paymentRequest->getResponseData());
    }

    public function test_completed_session_without_an_order_id_fails_without_calling_the_api(): void
    {
        $payment = self::payment();
        $details = PaymentDetails::of($payment);
        $details->setCompletedSessionId('s-1');
        $details->save();
        $paymentRequest = self::refundRequest($payment);

        $this->handle($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'No completed Przelewy24 transaction for this payment.'], $paymentRequest->getResponseData());
    }

    public function test_active_refund_is_not_requested_again(): void
    {
        $payment = self::payment();
        $details = PaymentDetails::of($payment);
        $details->recordTransaction(new Transaction('s-1', 1001, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
        $details->setCompletedSessionId('s-1');
        $details->recordRefund('uuid-1', 'request-1', 1500, 'PLN');
        $details->save();
        $paymentRequest = self::refundRequest($payment);
        $paymentLocker = $this->createMock(PaymentLockerInterface::class);
        $paymentLocker->expects(self::once())->method('lock');

        $this->handle($paymentRequest, $paymentLocker);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(['session_id' => 's-1', 'already_requested' => true], $paymentRequest->getResponseData());
        self::assertSame(['uuid-1'], array_keys(PaymentDetails::of($payment)->refunds()));
    }

    private function handle(PaymentRequestInterface $paymentRequest, ?PaymentLockerInterface $paymentLocker = null): void
    {
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);
        $client = $this->createMock(Przelewy24ClientInterface::class);
        $client->expects(self::never())->method('refund');

        $handler = new RefundPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $client,
            $paymentLocker ?? self::createStub(PaymentLockerInterface::class),
            self::createStub(UrlGeneratorInterface::class),
            new PaymentStateMachine(),
        );
        $handler(new RefundPaymentRequest(self::HASH));
    }

    private static function payment(): Payment
    {
        $method = FakePrzelewy24::paymentMethod();
        $payment = FakePrzelewy24::payment($method, PaymentInterface::STATE_REFUNDED);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('s-1', 'token-s-1', 1500, 'PLN');
        $details->save();

        return $payment;
    }

    private static function refundRequest(Payment $payment): PaymentRequestInterface
    {
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethod::class, $method);
        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_REFUND);

        return $paymentRequest;
    }
}
