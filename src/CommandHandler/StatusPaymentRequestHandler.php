<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24Exception;
use Refberg\SyliusPrzelewy24Plugin\Command\StatusPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use function array_slice;

/**
 * Runs when the customer comes back from Przelewy24 (after-pay) or when an API client asks for the status.
 *
 * The state is read from GET /transaction/by/sessionId; query parameters of the return URL are ignored.
 * A paid transaction is verified here as well, so the payment is completed even when no notification arrives.
 *
 * @internal
 */
final readonly class StatusPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    /** Only the newest open transactions are checked, so a payment with many attempts stays fast. */
    private const MAX_CHECKED_TRANSACTIONS = 3;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
            $this->paymentLocker->lock($payment);

            $details = PaymentDetails::of($payment);
            foreach (array_slice($details->getOpenSessionIds(), 0, self::MAX_CHECKED_TRANSACTIONS) as $sessionId) {
                try {
                    $result = $this->synchronizer->synchronize($payment, $sessionId, $credentials);
                } catch (Przelewy24ApiException $exception) {
                    // Assumption (not confirmed in the sandbox): an unpaid transaction may be reported as not found.
                    if ($exception->isNotFound()) {
                        continue;
                    }

                    throw $exception;
                }

                if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
                    $this->restorePaidMethod($payment, $method, $sessionId);
                }
            }
        } catch (Przelewy24Exception|InvalidConfigurationException $exception) {
            $this->logger->warning('Could not check Przelewy24 transaction status.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $currentSessionId = $details->getCurrentSessionId();

        $this->completeRequest($paymentRequest, [
            'payment_state' => $payment->getState(),
            'session_id' => $currentSessionId,
            'p24_status' => null !== $currentSessionId ? $details->getTransactionStatus($currentSessionId) : null,
        ]);
    }
}
