<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Command\StatusPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
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
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\MemoryLogger;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class StatusPaymentRequestHandlerTest extends TestCase
{
    #[TestWith([Transaction::STATUS_PAYMENT_MADE, PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith([Transaction::STATUS_ADVANCE_PAYMENT, PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith([Transaction::STATUS_NO_PAYMENT, PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_of_the_checked_transaction_is_completed_once_it_is_paid(int $p24Status, string $expectedState): void
    {
        $method = self::p24Method();
        $payment = self::payment($method, ['s-1', 's-2']);

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-1');
        $otherCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-2');
        $cancelledCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 's-1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $requests = [$capture, $otherCapture, $cancelledCapture, $statusRequest];

        $this->handle($statusRequest, $requests, ['s-1' => $p24Status, 's-2' => Transaction::STATUS_NO_PAYMENT]);

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(['redirect_url' => 'https://sandbox.przelewy24.pl/trnRequest/token-s-1', 'session_id' => 's-1'], $capture->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $cancelledCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    #[TestWith([Transaction::STATUS_PAYMENT_MADE])]
    #[TestWith([Transaction::STATUS_PAYMENT_RETURNED])]
    public function test_capture_request_left_processing_for_an_already_paid_transaction_is_completed(int $p24Status): void
    {
        $method = self::p24Method();
        $payment = self::payment($method, ['s-1']);
        $details = PaymentDetails::of($payment);
        $details->recordTransaction(new Transaction('s-1', 1001, $p24Status, 1500, 'PLN'));
        $details->save();

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);

        $this->handle($statusRequest, [$capture, $statusRequest], []);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    public function test_not_found_transaction_is_skipped_and_the_next_one_checked(): void
    {
        $method = self::p24Method();
        $payment = self::payment($method, ['s-1', 's-2']);
        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $checked = [];
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $sessionId) use (&$checked): string {
            $checked[] = $sessionId;
            if ('s-2' === $sessionId) {
                throw new Przelewy24ApiException('Transaction not found (HTTP 404).', 404);
            }
            $details = PaymentDetails::of($synchronized);
            $details->recordTransaction(new Transaction($sessionId, 1001, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $this->invoke($statusRequest, [$capture, $statusRequest], $synchronizer);

        self::assertSame(['s-2', 's-1'], $checked);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    public function test_other_api_error_fails_the_request(): void
    {
        $method = self::p24Method();
        $payment = self::payment($method, ['s-1']);
        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 's-1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new Przelewy24ApiException('Verifying transaction failed (HTTP 503).', 503));
        $logger = new MemoryLogger();

        $this->invoke($statusRequest, [$capture, $statusRequest], $synchronizer, $logger);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $statusRequest->getState());
        self::assertSame(['error' => 'Verifying transaction failed (HTTP 503).'], $statusRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $capture->getState());
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42, 'reason' => 'Verifying transaction failed (HTTP 503).'], $logger->records[0]['context']);
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     * @param array<string, int> $p24Statuses
     */
    private function handle(PaymentRequestInterface $statusRequest, array $requests, array $p24Statuses): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $sessionId) use ($p24Statuses): string {
            $details = PaymentDetails::of($synchronized);
            $details->recordTransaction(new Transaction($sessionId, 1001, $p24Statuses[$sessionId] ?? Transaction::STATUS_NO_PAYMENT, 1500, 'PLN'));
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $this->invoke($statusRequest, $requests, $synchronizer);
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function invoke(PaymentRequestInterface $statusRequest, array $requests, PaymentSynchronizerInterface $synchronizer, MemoryLogger $logger = new MemoryLogger()): void
    {
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($statusRequest);

        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler = new StatusPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $synchronizer,
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
            $paymentRequestRepository,
        );

        $handler(new StatusPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
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

    /**
     * @param list<string> $sessionIds
     */
    private static function payment(PaymentMethod $method, array $sessionIds): Payment
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
        foreach ($sessionIds as $sessionId) {
            $details->addTransaction($sessionId, 'token-' . $sessionId, 1500, 'PLN');
        }
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
