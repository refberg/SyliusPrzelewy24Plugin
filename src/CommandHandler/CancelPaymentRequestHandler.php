<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Command\CancelPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function sprintf;

/**
 * Runs after the payment was cancelled in Sylius (e.g. by an administrator).
 *
 * Przelewy24 has no API to cancel a registered transaction. Every transaction of the payment that may still
 * be paid is marked as cancelled, so the plugin never verifies it: if the customer pays it anyway,
 * Przelewy24 does not settle the money and the payment gets the "payment_completed_after_failure" warning.
 *
 * @internal
 */
final readonly class CancelPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CancelPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        if (!$this->credentialsResolver->supports($paymentRequest->getMethod())) {
            $this->failRequest($paymentRequest, 'Payment method is not a Przelewy24 payment method.');

            return;
        }

        $payment = self::corePayment($paymentRequest);

        $this->paymentLocker->lock($payment);
        if (PaymentInterface::STATE_CANCELLED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be cancelled.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);
        $cancelled = $details->getOpenSessionIds();
        foreach ($cancelled as $sessionId) {
            $details->cancelTransaction($sessionId);
        }
        $details->save();

        $this->logger->info('Przelewy24 transactions of a cancelled payment will not be verified.', [
            'payment_id' => $payment->getId(),
            'session_ids' => $cancelled,
        ]);

        $this->completeRequest($paymentRequest, ['cancelled_session_ids' => $cancelled]);
    }
}
