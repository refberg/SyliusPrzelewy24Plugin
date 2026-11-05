<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Notification\Notification;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Handles a Przelewy24 notification stored by Sylius' notify endpoint.
 *
 * The "sign" is verified again here (the notify payment provider already rejected bad ones), then
 * the transaction is read from the Przelewy24 API, so a stale or replayed notification cannot move
 * the payment backwards. The payment is matched by the sessionId recorded in its details, not by its
 * current payment method.
 *
 * Transient API failures and rejected plugin credentials (HTTP 401/403) are rethrown:
 * with a sync:// payment request transport the endpoint then answers 5xx and Przelewy24 retries.
 *
 * @internal
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
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
            $this->handleRefund($notification, $details, $payment);
            $this->completeRequest($paymentRequest, [
                'type' => Notification::TYPE_REFUND,
                'refunds_uuid' => $notification->refundsUuid,
                'refund_status' => $notification->refundStatus,
            ]);

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $notification->sessionId, $credentials, $notification->orderId);
        } catch (Przelewy24ApiException $exception) {
            // Rejected credentials are fixed by the merchant, so Przelewy24 should keep retrying until then.
            if ($exception->isTransient() || $exception->isAuthenticationFailure()) {
                throw $exception;
            }

            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $notification->sessionId);
        }

        $this->completeRequest($paymentRequest, [
            'type' => Notification::TYPE_TRANSACTION,
            'session_id' => $notification->sessionId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }

    private function handleRefund(Notification $notification, PaymentDetails $details, PaymentInterface $payment): void
    {
        $refundsUuid = (string) $notification->refundsUuid;
        $status = (string) $notification->refundStatus;

        if ($details->hasRefund($refundsUuid)) {
            $details->updateRefundStatus($refundsUuid, $status);
            $details->save();

            if (Refund::STATUS_REJECTED === $status) {
                $this->logger->error('Przelewy24 rejected a refund; it has to be handled manually.', [
                    'payment_id' => $payment->getId(),
                    'session_id' => $notification->sessionId,
                    'refunds_uuid' => $refundsUuid,
                ]);
            }
            $this->refundPaymentWhenCovered($details, $payment);

            return;
        }

        $this->handleExternalRefund($notification, $refundsUuid, $status, $details, $payment);
    }

    /**
     * A refund made outside Sylius, e.g. in the Przelewy24 panel. It is recorded with the "external" flag,
     * and once completed refunds cover the whole payment, the payment is moved to "refunded".
     * A partial refund does not change the payment state: Sylius has no state for it.
     */
    private function handleExternalRefund(Notification $notification, string $refundsUuid, string $status, PaymentDetails $details, PaymentInterface $payment): void
    {
        $completedSessionId = $details->getCompletedSessionId();
        if (
            null === $completedSessionId ||
            $notification->sessionId !== $completedSessionId ||
            $notification->orderId !== $details->getOrderId($completedSessionId) ||
            $notification->currency !== $payment->getCurrencyCode()
        ) {
            $this->logger->warning('Ignored Przelewy24 refund notification that does not match the paid transaction.', [
                'payment_id' => $payment->getId(),
                'session_id' => $notification->sessionId,
                'refunds_uuid' => $refundsUuid,
            ]);

            return;
        }

        if (!$details->recordExternalRefund($refundsUuid, $status, $notification->amount, $notification->currency)) {
            return;
        }
        $details->save();

        $this->logger->info('Recorded a Przelewy24 refund made outside Sylius.', [
            'payment_id' => $payment->getId(),
            'refunds_uuid' => $refundsUuid,
            'status' => $status,
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
