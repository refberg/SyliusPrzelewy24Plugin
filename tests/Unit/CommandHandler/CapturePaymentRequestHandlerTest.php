<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24Client;
use Refberg\SyliusPrzelewy24Plugin\Command\CapturePaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGenerator;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;

final class CapturePaymentRequestHandlerTest extends TestCase
{
    public function test_payment_completed_while_waiting_for_the_lock_gets_no_new_transaction(): void
    {
        $p24 = new FakePrzelewy24();

        $method = FakePrzelewy24::paymentMethod();
        $payment = FakePrzelewy24::payment($method, PaymentInterface::STATE_NEW);

        $paymentRequest = new class($payment, $method) extends PaymentRequest {
            public function getId(): string
            {
                return '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
            }
        };

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $transactionRequestFactory = self::createStub(TransactionRequestFactoryInterface::class);
        $transactionRequestFactory->method('create')->willReturnCallback(static fn (PaymentRequestInterface $request, PaymentInterface $payment, string $sessionId): array => [
            'sessionId' => $sessionId, 'amount' => 1500, 'currency' => 'PLN', 'description' => 'Order', 'email' => 'buyer@example.com',
            'country' => 'PL', 'language' => 'pl', 'urlReturn' => 'https://shop.example/return',
        ]);

        // A notification completes the payment while the capture waits for the lock.
        $paymentLocker = self::createStub(PaymentLockerInterface::class);
        $paymentLocker->method('lock')->willReturnCallback(static function (PaymentInterface $locked): void {
            $locked->setState(PaymentInterface::STATE_COMPLETED);
        });

        $handler = new CapturePaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new Przelewy24Client(new MockHttpClient($p24, 'https://' . FakePrzelewy24::HOST), new SignatureVerifier()),
            $transactionRequestFactory,
            new SessionIdGenerator(),
            $paymentLocker,
            new PaymentStateMachine(),
        );

        $handler(new CapturePaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame([], $p24->requests());
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be paid.'], $paymentRequest->getResponseData());
    }
}
