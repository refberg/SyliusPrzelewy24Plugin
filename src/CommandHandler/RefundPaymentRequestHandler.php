<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24Exception;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundRequest;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Command\RefundPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function sprintf;
use function str_replace;

/**
 * Requests a full refund of the Przelewy24 transaction that paid the payment, less the refunds that are not rejected
 * (including refunds made outside Sylius, e.g. in the Przelewy24 panel).
 *
 * requestId is the payment request hash and refundsUuid the same hash without dashes, so a refund notification
 * is matched back to this refund. The final refund status arrives later as a signed refund notification.
 *
 * @internal
 */
final readonly class RefundPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private Przelewy24ClientInterface $client,
        private PaymentLockerInterface $paymentLocker,
        private UrlGeneratorInterface $urlGenerator,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RefundPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->paymentLocker->lock($payment);
        if (PaymentInterface::STATE_REFUNDED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be refunded.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);

        $sessionId = $details->getCompletedSessionId();
        $orderId = null !== $sessionId ? $details->getOrderId($sessionId) : null;
        if (null === $sessionId || null === $orderId) {
            $this->logger->error('Przelewy24 refund not requested: the payment was not completed through Przelewy24.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'No completed Przelewy24 transaction for this payment.');

            return;
        }

        if ($details->hasActiveRefund()) {
            $this->completeRequest($paymentRequest, ['session_id' => $sessionId, 'already_requested' => true]);

            return;
        }

        $amount = (int) $payment->getAmount() - $details->activeRefundAmount();
        if ($amount <= 0) {
            $this->logger->info('Przelewy24 refund not requested: refunds made outside Sylius already cover the payment.', [
                'payment_id' => $payment->getId(),
                'session_id' => $sessionId,
            ]);
            $this->completeRequest($paymentRequest, ['session_id' => $sessionId, 'covered_by_external_refunds' => true]);

            return;
        }

        $order = $payment->getOrder();
        $requestId = (string) $paymentRequest->getId();
        $refundsUuid = str_replace('-', '', $requestId);

        try {
            $urlStatus = $this->urlGenerator->generate(Przelewy24Gateway::NOTIFY_ROUTE, ['code' => $method->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RoutingExceptionInterface $exception) {
            $this->logger->error('Przelewy24 refund not requested: the payment notification route is missing.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        try {
            $this->client->refund($credentials, new RefundRequest(
                requestId: $requestId,
                refundsUuid: $refundsUuid,
                orderId: $orderId,
                sessionId: $sessionId,
                amount: $amount,
                description: sprintf('Refund %s', $order instanceof OrderInterface ? (string) $order->getNumber() : ''),
                urlStatus: $urlStatus,
            ));
        } catch (Przelewy24Exception $exception) {
            $this->logger->error('Przelewy24 refund request failed; refund has to be handled manually.', [
                'payment_id' => $payment->getId(),
                'session_id' => $sessionId,
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details->recordRefund($refundsUuid, $requestId, $amount, (string) $payment->getCurrencyCode());
        $details->save();

        $this->completeRequest($paymentRequest, [
            'session_id' => $sessionId,
            'refunds_uuid' => $refundsUuid,
            'amount' => $amount,
        ]);
    }
}
