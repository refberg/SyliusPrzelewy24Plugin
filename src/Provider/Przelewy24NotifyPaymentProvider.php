<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Provider;

use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Notification\Notification;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Finds the payment a Przelewy24 notification belongs to.
 *
 * Runs before Sylius stores anything, so notifications with a missing or wrong "sign"
 * are rejected with HTTP 400 and never create a payment request. The payment is found by the
 * sessionId and must have that transaction in its details, whatever its current payment method is.
 */
final readonly class Przelewy24NotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param RepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private SessionIdGeneratorInterface $sessionIdGenerator,
        private RepositoryInterface $paymentRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return $this->credentialsResolver->supports($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        try {
            $credentials = $this->credentialsResolver->resolve($paymentMethod);
        } catch (InvalidConfigurationException) {
            throw new ServiceUnavailableHttpException(null, 'Przelewy24 payment method is not configured.');
        }

        $notification = $this->notificationParser->parse($request->getContent(), $credentials);
        if (!$notification instanceof Notification) {
            throw new BadRequestHttpException('Invalid Przelewy24 notification sign or body.');
        }

        $paymentId = $this->sessionIdGenerator->extractPaymentId($notification->sessionId);
        $payment = null !== $paymentId ? $this->paymentRepository->find($paymentId) : null;
        if (!$payment instanceof PaymentInterface || !PaymentDetails::of($payment)->hasTransaction($notification->sessionId)) {
            throw new NotFoundHttpException('No payment matches this Przelewy24 notification.');
        }

        return $payment;
    }
}
