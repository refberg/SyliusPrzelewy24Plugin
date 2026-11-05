<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Provider;

use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers an accepted Przelewy24 notification with HTTP 200. Other gateways keep the core response.
 */
final readonly class NotifyResponseProvider implements NotifyResponseProviderInterface
{
    public function __construct(
        private NotifyResponseProviderInterface $decorated,
        private CredentialsResolverInterface $credentialsResolver,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        if (!$this->credentialsResolver->supports($paymentRequest->getMethod())) {
            return $this->decorated->provide($paymentRequest);
        }

        return new Response('', Response::HTTP_OK);
    }
}
