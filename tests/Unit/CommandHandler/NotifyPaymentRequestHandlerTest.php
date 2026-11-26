<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\Notification\Notification;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    public function test_notification_completes_only_the_capture_request_of_its_transaction(): void
    {
        $method = self::p24Method();
        $payment = self::payment($method);
        // The other transaction is paid as well, so only the session filter keeps its capture request open.
        $details = PaymentDetails::of($payment);
        $details->recordTransaction(new Transaction('s-2', 1002, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN'));
        $details->save();

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-1');
        $otherCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-2');
        $notifyRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_NOTIFY, PaymentRequestInterface::STATE_NEW, null);
        $requests = [$capture, $otherCapture, $notifyRequest];

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($notifyRequest);

        $notificationParser = self::createStub(NotificationParserInterface::class);
        $notificationParser->method('parseStoredPayload')->willReturn(new Notification(Notification::TYPE_TRANSACTION, 's-1', 1001, 1500, 'PLN'));

        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $sessionId): string {
            $details = PaymentDetails::of($synchronized);
            $details->recordTransaction(new Transaction($sessionId, 1001, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler = new NotifyPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $notificationParser,
            $synchronizer,
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            paymentRequestRepository: $paymentRequestRepository,
        );

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $notifyRequest->getState());
    }

    private static function p24Method(): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(Przelewy24Gateway::FACTORY_NAME);
        $gatewayConfig->setConfig([
            Przelewy24Gateway::CONFIG_ENVIRONMENT => 'sandbox',
            Przelewy24Gateway::CONFIG_MERCHANT_ID => (string) FakePrzelewy24::MERCHANT_ID,
            Przelewy24Gateway::CONFIG_CRC_KEY => FakePrzelewy24::CRC_KEY,
            Przelewy24Gateway::CONFIG_API_KEY => FakePrzelewy24::API_KEY,
        ]);
        $method = new PaymentMethod();
        $method->setCode('P24');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }

    private static function payment(PaymentMethod $method): Payment
    {
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setState(PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('s-1', 'token-s-1', 1500, 'PLN');
        $details->addTransaction('s-2', 'token-s-2', 1500, 'PLN');
        $details->save();

        return $payment;
    }

    private static function request(PaymentInterface $payment, PaymentMethod $method, string $action, string $state, ?string $sessionId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState($state);
        if (null !== $sessionId) {
            $request->setResponseData(['redirect_url' => 'https://sandbox.przelewy24.pl/trnRequest/token-' . $sessionId, 'session_id' => $sessionId]);
        }

        return $request;
    }
}
