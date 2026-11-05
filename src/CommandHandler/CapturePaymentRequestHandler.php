<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\CommandHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24Exception;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Command\CapturePaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use function sprintf;

/**
 * Registers a Przelewy24 transaction and stores the payment page URL in the payment request response data.
 *
 * It never completes the payment: only a verified transaction read from the Przelewy24 API can.
 *
 * @internal
 */
final readonly class CapturePaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private Przelewy24ClientInterface $client,
        private TransactionRequestFactoryInterface $transactionRequestFactory,
        private SessionIdGeneratorInterface $sessionIdGenerator,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        // A processing request already has a transaction: re-sending the customer there is enough.
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());
            $this->paymentLocker->lock($payment);
            if (PaymentInterface::STATE_NEW !== $payment->getState()) {
                $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be paid.', (string) $payment->getState()));

                return;
            }

            $sessionId = $this->sessionIdGenerator->generate($payment, $paymentRequest);
            $transaction = $this->transactionRequestFactory->create($paymentRequest, $payment, $sessionId);
            $registered = $this->client->registerTransaction($credentials, $transaction);
        } catch (Przelewy24Exception|InvalidConfigurationException|InvalidArgumentException $exception) {
            $this->logger->warning('Could not register Przelewy24 transaction.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $details->addTransaction($sessionId, $registered->token, (int) $payment->getAmount(), (string) $payment->getCurrencyCode());
        $details->save();

        $paymentRequest->setResponseData([
            'redirect_url' => $registered->redirectUrl,
            'session_id' => $sessionId,
        ]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }
}
