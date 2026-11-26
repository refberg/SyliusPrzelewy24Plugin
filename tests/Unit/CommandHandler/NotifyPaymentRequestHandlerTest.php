<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundListed;
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
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\MemoryLogger;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\PaymentStateMachine;
use function array_column;
use function array_filter;
use function array_keys;
use function array_values;
use function in_array;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    private const HASH = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

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

    #[TestWith([500, true])]
    #[TestWith([503, true])]
    #[TestWith([401, true])]
    #[TestWith([403, true])]
    #[TestWith([400, false])]
    public function test_refund_read_error_follows_the_retry_policy(int $httpStatus, bool $rethrown): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willThrowException(new Przelewy24ApiException('Reading Przelewy24 refunds failed.', $httpStatus));
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('uuid-1', null));
        self::recordRefund($payment);

        try {
            $handler(new NotifyPaymentRequest(self::HASH));
            self::assertFalse($rethrown);
            self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
            self::assertSame(['error' => 'Reading Przelewy24 refunds failed.'], $paymentRequest->getResponseData());
        } catch (Przelewy24ApiException) {
            self::assertTrue($rethrown);
            self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        }
        self::assertSame(Refund::STATUS_PENDING, PaymentDetails::of($payment)->refunds()['uuid-1']['status'] ?? null);
    }

    public function test_refund_of_sylius_is_read_by_its_recorded_request_id(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::once())->method('retrieveRefund')
            ->with(self::isInstanceOf(PaymentInterface::class), 1001, 'request-1', self::anything())
            ->willReturn(new RefundListed('request-1', Refund::STATUS_REJECTED, 1500, 'PLN'));
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('uuid-1', 'forged-request', Refund::STATUS_REJECTED));
        self::recordRefund($payment);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(['type' => Notification::TYPE_REFUND, 'refunds_uuid' => 'uuid-1', 'refund_status' => Refund::STATUS_REJECTED], $paymentRequest->getResponseData());
        self::assertSame(Refund::STATUS_REJECTED, PaymentDetails::of($payment)->refunds()['uuid-1']['status'] ?? null);
    }

    /**
     * @param Refund::STATUS_* $apiStatus
     */
    #[TestWith(['panel-1', 1500, 1000, 'PLN', Refund::STATUS_COMPLETED])]
    #[TestWith(['panel-1', 1500, 1500, 'EUR', Refund::STATUS_COMPLETED])]
    #[TestWith(['panel-1', 1500, 1500, 'PLN', Refund::STATUS_REJECTED])]
    #[TestWith(['uuid-1', 1500, 1500, 'PLN', Refund::STATUS_REJECTED])]
    #[TestWith(['uuid-1', 1000, 1000, 'PLN', Refund::STATUS_COMPLETED])]
    public function test_refund_that_differs_from_the_signed_notification_or_the_recorded_refund_fails_without_changes(
        string $refundsUuid,
        int $notifiedAmount,
        int $apiAmount,
        string $apiCurrency,
        string $apiStatus,
    ): void {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundListed('request-1', $apiStatus, $apiAmount, $apiCurrency));
        $logger = new MemoryLogger();
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification($refundsUuid, 'request-1', amount: $notifiedAmount), logger: $logger);
        self::recordRefund($payment, 'request-0');
        $before = PaymentDetails::of($payment)->refunds();

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'The Przelewy24 API lists this refund with another amount, currency or status.'], $paymentRequest->getResponseData());
        self::assertSame($before, PaymentDetails::of($payment)->refunds());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(['warning'], array_column($logger->records, 'level'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_refund_of_sylius_not_listed_by_the_api_yet_is_retried(bool $notFound): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        if ($notFound) {
            $synchronizer->method('retrieveRefund')->willThrowException(new Przelewy24ApiException('Reading Przelewy24 refunds failed (HTTP 404).', 404));
        } else {
            $synchronizer->method('retrieveRefund')->willReturn(null);
        }
        $logger = new MemoryLogger();
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('uuid-1', null), logger: $logger);
        self::recordRefund($payment);

        try {
            $handler(new NotifyPaymentRequest(self::HASH));
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertSame(503, $exception->getHttpStatusCode());
        }

        self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        self::assertSame(Refund::STATUS_PENDING, PaymentDetails::of($payment)->refunds()['uuid-1']['status'] ?? null);
        self::assertSame([[
            'level' => 'warning',
            'message' => 'Przelewy24 API does not list the notified refund yet; the notification will be retried.',
            'context' => ['payment_id' => 42, 'session_id' => 's-1', 'p24_order_id' => 1001, 'refunds_uuid' => 'uuid-1', 'request_id' => 'request-1'],
        ]], $logger->records);
    }

    #[TestWith([false, 'The Przelewy24 API does not list this refund for the transaction.'])]
    #[TestWith([true, 'Reading Przelewy24 refunds failed (HTTP 404).'])]
    public function test_refund_made_outside_sylius_and_not_listed_by_the_api_fails(bool $notFound, string $error): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        if ($notFound) {
            $synchronizer->method('retrieveRefund')->willThrowException(new Przelewy24ApiException($error, 404));
        } else {
            $synchronizer->method('retrieveRefund')->willReturn(null);
        }
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('panel-1', 'panel-request-1'));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => $error], $paymentRequest->getResponseData());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    public function test_refund_notification_without_a_request_id_fails_without_api_calls(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('retrieveRefund');
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('panel-1', null));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Przelewy24 refund notification has no requestId.'], $paymentRequest->getResponseData());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    public function test_final_status_not_yet_shown_by_the_api_is_retried_without_changing_the_state(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundListed('request-1', Refund::STATUS_PENDING, 1500, 'PLN'));
        $logger = new MemoryLogger();
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('uuid-1', null), logger: $logger);
        self::recordRefund($payment);

        try {
            $handler(new NotifyPaymentRequest(self::HASH));
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertTrue($exception->isTransient());
        }

        self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        self::assertSame(Refund::STATUS_PENDING, PaymentDetails::of($payment)->refunds()['uuid-1']['status'] ?? null);
        self::assertSame([[
            'level' => 'warning',
            'message' => 'Przelewy24 API does not show the notified final refund status yet; the notification will be retried.',
            'context' => [
                'payment_id' => 42,
                'session_id' => 's-1',
                'p24_order_id' => 1001,
                'refunds_uuid' => 'uuid-1',
                'request_id' => 'request-1',
                'notified_status' => Refund::STATUS_COMPLETED,
                'api_status' => Refund::STATUS_PENDING,
                'notified_amount' => 1500,
                'api_amount' => 1500,
            ],
        ]], $logger->records);
    }

    public function test_external_refund_with_an_already_recorded_request_id_is_ignored(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundListed('request-1', Refund::STATUS_COMPLETED, 1500, 'PLN'));
        [$handler, $paymentRequest, $payment] = $this->refundHandler($synchronizer, self::refundNotification('panel-1', 'request-1'));
        self::recordRefund($payment);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(['uuid-1'], array_keys(PaymentDetails::of($payment)->refunds()));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function test_duplicate_completed_refund_notification_refunds_the_payment_once(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundListed('panel-request-1', Refund::STATUS_COMPLETED, 1500, 'PLN'));
        $stateMachine = new PaymentStateMachine();
        [$handler, , $payment, $method] = $this->refundHandler($synchronizer, self::refundNotification('panel-1', 'panel-request-1'), stateMachine: $stateMachine);
        [$duplicate] = $this->refundHandler($synchronizer, self::refundNotification('panel-1', 'panel-request-1'), $method, $payment, $stateMachine);

        $handler(new NotifyPaymentRequest(self::HASH));
        $duplicate(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertCount(1, array_filter($stateMachine->applied, static fn (array $applied): bool => PaymentTransitions::TRANSITION_REFUND === $applied[1]));
        self::assertSame(1500, PaymentDetails::of($payment)->completedRefundAmount());
    }

    /**
     * @return array{NotifyPaymentRequestHandler, PaymentRequestInterface, Payment, PaymentMethod}
     */
    private function refundHandler(
        PaymentSynchronizerInterface $synchronizer,
        Notification $notification,
        ?PaymentMethod $method = null,
        ?Payment $payment = null,
        PaymentStateMachine $stateMachine = new PaymentStateMachine(),
        LoggerInterface $logger = new NullLogger(),
    ): array {
        $method ??= self::p24Method();
        $payment ??= self::paidPayment($method);
        $paymentRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_NOTIFY, PaymentRequestInterface::STATE_NEW, null);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $notificationParser = self::createStub(NotificationParserInterface::class);
        $notificationParser->method('parseStoredPayload')->willReturn($notification);

        $handler = new NotifyPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $notificationParser,
            $synchronizer,
            self::createStub(PaymentLockerInterface::class),
            $stateMachine,
            $logger,
        );

        return [$handler, $paymentRequest, $payment, $method];
    }

    private static function refundNotification(string $refundsUuid, ?string $requestId, string $status = Refund::STATUS_COMPLETED, int $amount = 1500): Notification
    {
        return new Notification(Notification::TYPE_REFUND, 's-1', 1001, $amount, 'PLN', $refundsUuid, $status, $requestId);
    }

    private static function paidPayment(PaymentMethod $method): Payment
    {
        $payment = self::payment($method);
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $details = PaymentDetails::of($payment);
        $details->recordTransaction(new Transaction('s-1', 1001, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'));
        $details->setCompletedSessionId('s-1');
        $details->save();

        return $payment;
    }

    private static function recordRefund(Payment $payment, string $requestId = 'request-1'): void
    {
        $details = PaymentDetails::of($payment);
        $details->recordRefund('uuid-1', $requestId, 1500, 'PLN');
        $details->save();
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
