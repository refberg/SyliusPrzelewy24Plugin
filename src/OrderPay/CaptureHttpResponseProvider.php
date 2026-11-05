<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\OrderPay;

use LogicException;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function is_string;
use function str_starts_with;

/**
 * Sends the shop customer to the Przelewy24 payment page.
 *
 * Sylius 2.2 passes a RequestConfiguration, Sylius 2.3 a Request; neither is used.
 */
final readonly class CaptureHttpResponseProvider implements HttpResponseProviderInterface
{
    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState() &&
            null !== self::redirectUrl($paymentRequest);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): RedirectResponse
    {
        $url = self::redirectUrl($paymentRequest) ?? throw new LogicException('Przelewy24 redirect URL is missing.');

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    private static function redirectUrl(PaymentRequestInterface $paymentRequest): ?string
    {
        $url = $paymentRequest->getResponseData()['redirect_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
