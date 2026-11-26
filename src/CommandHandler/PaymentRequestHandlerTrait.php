<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use function in_array;
use function is_string;

/**
 * @internal
 *
 * @property StateMachineInterface $stateMachine
 * @property LoggerInterface $logger
 */
trait PaymentRequestHandlerTrait
{
    /**
     * @param array<string, mixed> $responseData
     */
    private function completeRequest(PaymentRequestInterface $paymentRequest, array $responseData): void
    {
        $paymentRequest->setResponseData($responseData);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function failRequest(PaymentRequestInterface $paymentRequest, string $reason): void
    {
        $paymentRequest->setResponseData(['error' => $reason]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function transitionRequest(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }

    /**
     * Sylius reuses a capture request that is not final, so a paid transaction would send the customer back to its payment page.
     *
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     * @param string|null $sessionId null closes the capture requests of every paid transaction
     */
    private function closeCaptureRequests(?PaymentRequestRepositoryInterface $paymentRequestRepository, PaymentInterface $payment, ?string $sessionId = null): void
    {
        if (!$paymentRequestRepository instanceof PaymentRequestRepositoryInterface) {
            return;
        }

        $details = PaymentDetails::of($payment);
        foreach ($paymentRequestRepository->findByPaymentIdAndStates($payment->getId(), [PaymentRequestInterface::STATE_PROCESSING]) as $captureRequest) {
            $captureSessionId = $captureRequest->getResponseData()['session_id'] ?? null;
            if (
                !is_string($captureSessionId) ||
                (null !== $sessionId && $sessionId !== $captureSessionId) ||
                !in_array($captureRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
            ) {
                continue;
            }

            if (Transaction::isPaidStatus($details->getTransactionStatus($captureSessionId))) {
                $this->transitionRequest($captureRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
            }
        }
    }

    /**
     * Refunds from Sylius use the payment's method, so it must be the Przelewy24 method that was paid.
     * Called when a transaction completed the payment after the customer switched it to another method.
     */
    private function restorePaidMethod(PaymentInterface $payment, PaymentMethodInterface $method, string $sessionId): void
    {
        if ($payment->getMethod() === $method) {
            return;
        }

        $previousMethod = $payment->getMethod();
        $payment->setMethod($method);

        // The applier saved its own details; a fresh read keeps completed_session_id.
        $details = PaymentDetails::of($payment);
        $details->addWarning(PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, $sessionId);
        $details->save();
        $this->logger->warning('Przelewy24 paid the payment after the customer switched to another payment method; the Przelewy24 method was restored.', [
            'payment_id' => $payment->getId(),
            'session_id' => $sessionId,
            'switched_to_method' => $previousMethod?->getCode(),
        ]);
    }

    private static function corePayment(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new LogicException('Przelewy24 payment requests require a Sylius Core payment.');
        }

        return $payment;
    }
}
