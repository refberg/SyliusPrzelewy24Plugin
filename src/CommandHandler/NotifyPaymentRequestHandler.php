<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundListed;
use Refberg\SyliusPrzelewy24Plugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Notification\Notification;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * Handles a Przelewy24 notification stored by Sylius' notify endpoint.
 *
 * The "sign" is verified again here (the notify payment provider already rejected bad ones), then
 * the transaction is read from the Przelewy24 API, so a stale or replayed notification cannot move
 * the payment backwards. The payment is matched by the sessionId recorded in its details, not by its
 * current payment method.
 *
 * Refund notifications only identify the refund: its status and amount are read from the Przelewy24 API.
 *
 * Transient API failures, rejected plugin credentials (HTTP 401/403) and a final refund status not yet shown by the
 * Przelewy24 API are rethrown, so the notification is retried (by Przelewy24 after HTTP 5xx with a sync:// payment
 * request transport, or by Messenger with an asynchronous one).
 *
 * @internal
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     */
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
        private ?PaymentRequestRepositoryInterface $paymentRequestRepository = null,
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $notification = $this->notificationParser->parseStoredPayload($paymentRequest->getPayload(), $credentials);
        if (!$notification instanceof Notification) {
            $this->logger->warning('Rejected Przelewy24 notification with an invalid sign or body.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'Invalid notification sign or body.');

            return;
        }

        $this->paymentLocker->lock($payment);
        $details = PaymentDetails::of($payment);

        if (!$details->hasTransaction($notification->sessionId)) {
            $this->failRequest($paymentRequest, 'Przelewy24 transaction is not linked to this payment.');

            return;
        }

        if (Notification::TYPE_REFUND === $notification->type) {
            $refundsUuid = (string) $notification->refundsUuid;
            // The notification's requestId is not signed, so it is used only for a refund the payment does not know yet.
            $requestId = $details->getRefundRequestId($refundsUuid) ?? $notification->requestId;
            if (null === $requestId) {
                $this->failRequest($paymentRequest, 'Przelewy24 refund notification has no requestId.');

                return;
            }

            $refund = $this->confirmedRefund($paymentRequest, $notification, $refundsUuid, $requestId, $details, $payment, $credentials);
            if (!$refund instanceof RefundListed) {
                return;
            }

            $this->handleRefund($notification, $refundsUuid, $refund, $details, $payment);
            $this->completeRequest($paymentRequest, [
                'type' => Notification::TYPE_REFUND,
                'refunds_uuid' => $refundsUuid,
                'refund_status' => $refund->status,
            ]);

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $notification->sessionId, $credentials, $notification->orderId);
        } catch (Przelewy24ApiException $exception) {
            $this->failOrRethrow($paymentRequest, $exception);

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $notification->sessionId);
        }
        $this->closeCaptureRequests($this->paymentRequestRepository, $payment, $notification->sessionId);

        $this->completeRequest($paymentRequest, [
            'type' => Notification::TYPE_TRANSACTION,
            'session_id' => $notification->sessionId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }

    /**
     * The refund read from the Przelewy24 API, when it matches the signed notification. Otherwise the payment request
     * is failed, or the notification is retried while the API has not caught up with it.
     *
     * @throws Przelewy24ApiException
     */
    private function confirmedRefund(
        PaymentRequestInterface $paymentRequest,
        Notification $notification,
        string $refundsUuid,
        string $requestId,
        PaymentDetails $details,
        PaymentInterface $payment,
        Przelewy24Credentials $credentials,
    ): ?RefundListed {
        $context = [
            'payment_id' => $payment->getId(),
            'session_id' => $notification->sessionId,
            'p24_order_id' => $notification->orderId,
            'refunds_uuid' => $refundsUuid,
            'request_id' => $requestId,
        ];
        $known = $details->hasRefund($refundsUuid);

        try {
            $refund = $this->synchronizer->retrieveRefund($payment, $notification->orderId, $requestId, $credentials);
        } catch (Przelewy24ApiException $exception) {
            if (!$known || !$exception->isNotFound()) {
                $this->failOrRethrow($paymentRequest, $exception);

                return null;
            }

            $refund = null;
        }

        if (!$refund instanceof RefundListed) {
            // Sylius requested this refund, so Przelewy24 is expected to list it soon.
            if ($known) {
                $this->logger->warning('Przelewy24 API does not list the notified refund yet; the notification will be retried.', $context);

                throw new Przelewy24ApiException('Przelewy24 API does not list the notified refund yet.', 503);
            }

            $this->failRequest($paymentRequest, 'The Przelewy24 API does not list this refund for the transaction.');

            return null;
        }

        $context += [
            'notified_status' => $notification->refundStatus,
            'api_status' => $refund->status,
            'notified_amount' => $notification->amount,
            'api_amount' => $refund->amount,
        ];

        // The signed body never changes the state, but a refund that differs from it is not trusted.
        if (
            $refund->amount !== $notification->amount ||
            $refund->currency !== $notification->currency ||
            ($known && $refund->amount !== $details->getRefundAmount($refundsUuid)) ||
            (Refund::isFinalStatus($refund->status) && $refund->status !== $notification->refundStatus)
        ) {
            $this->logger->warning('Ignored Przelewy24 refund notification that does not match the refund listed by the API.', $context);
            $this->failRequest($paymentRequest, 'The Przelewy24 API lists this refund with another amount, currency or status.');

            return null;
        }

        // A refund notification always reports a final status, so the API has not caught up yet.
        if (!Refund::isFinalStatus($refund->status)) {
            $this->logger->warning('Przelewy24 API does not show the notified final refund status yet; the notification will be retried.', $context);

            throw new Przelewy24ApiException('Przelewy24 API does not show the notified final refund status yet.', 503);
        }

        return $refund;
    }

    /**
     * @throws Przelewy24ApiException
     */
    private function failOrRethrow(PaymentRequestInterface $paymentRequest, Przelewy24ApiException $exception): void
    {
        // Rejected credentials are fixed by the merchant, so Przelewy24 should keep retrying until then.
        if ($exception->isTransient() || $exception->isAuthenticationFailure()) {
            throw $exception;
        }

        $this->failRequest($paymentRequest, $exception->getMessage());
    }

    private function handleRefund(Notification $notification, string $refundsUuid, RefundListed $refund, PaymentDetails $details, PaymentInterface $payment): void
    {
        if ($details->hasRefund($refundsUuid)) {
            $details->updateRefundStatus($refundsUuid, $refund->status);
            $details->save();

            if (Refund::STATUS_REJECTED === $refund->status) {
                $this->logger->error('Przelewy24 rejected a refund; it has to be handled manually.', [
                    'payment_id' => $payment->getId(),
                    'session_id' => $notification->sessionId,
                    'refunds_uuid' => $refundsUuid,
                ]);
            }
            $this->refundPaymentWhenCovered($details, $payment);

            return;
        }

        $this->handleExternalRefund($notification, $refundsUuid, $refund, $details, $payment);
    }

    /**
     * A refund made outside Sylius, e.g. in the Przelewy24 panel. It is recorded with the "external" flag,
     * and once completed refunds cover the whole payment, the payment is moved to "refunded".
     * A partial refund does not change the payment state: Sylius has no state for it.
     */
    private function handleExternalRefund(Notification $notification, string $refundsUuid, RefundListed $refund, PaymentDetails $details, PaymentInterface $payment): void
    {
        $completedSessionId = $details->getCompletedSessionId();
        if (
            null === $completedSessionId ||
            $notification->sessionId !== $completedSessionId ||
            $notification->orderId !== $details->getOrderId($completedSessionId) ||
            $refund->amount <= 0 ||
            $refund->amount > (int) $payment->getAmount() ||
            // requestId is not signed: without this check one listed refund could be recorded under several refundsUuid.
            // Identical panel refunds stay indistinguishable, so a swapped requestId can only leave one unrecorded.
            $details->hasRefundWithRequestId($refund->requestId)
        ) {
            $this->logger->warning('Ignored Przelewy24 refund notification that does not match the paid transaction.', [
                'payment_id' => $payment->getId(),
                'session_id' => $notification->sessionId,
                'refunds_uuid' => $refundsUuid,
            ]);

            return;
        }

        if (!$details->recordExternalRefund($refundsUuid, $refund->requestId, $refund->status, $refund->amount, $refund->currency)) {
            return;
        }
        $details->save();

        $this->logger->info('Recorded a Przelewy24 refund made outside Sylius.', [
            'payment_id' => $payment->getId(),
            'refunds_uuid' => $refundsUuid,
            'status' => $refund->status,
        ]);

        $this->refundPaymentWhenCovered($details, $payment);
    }

    private function refundPaymentWhenCovered(PaymentDetails $details, PaymentInterface $payment): void
    {
        if (
            $details->completedRefundAmount() >= (int) $payment->getAmount() &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }
    }
}
