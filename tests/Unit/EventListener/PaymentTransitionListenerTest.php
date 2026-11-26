<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;

final class PaymentTransitionListenerTest extends TestCase
{
    public function test_existing_payment_request_is_not_created_again(): void
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(Przelewy24Gateway::FACTORY_NAME);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);
        $payment = new Payment();
        $payment->setMethod($method);

        $paymentRequestRepository = $this->createMock(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findOneByActionPaymentAndMethod')
            ->with(PaymentRequestInterface::ACTION_REFUND, $payment, $method)
            ->willReturn(new PaymentRequest($payment, $method));
        $paymentRequestRepository->expects(self::never())->method('add');
        $paymentRequestFactory = $this->createMock(PaymentRequestFactoryInterface::class);
        $paymentRequestFactory->expects(self::never())->method('create');
        $paymentRequestAnnouncer = $this->createMock(PaymentRequestAnnouncerInterface::class);
        $paymentRequestAnnouncer->expects(self::never())->method('dispatchPaymentRequestCommand');

        $listener = new PaymentTransitionListener(new CredentialsResolver(), $paymentRequestFactory, $paymentRequestRepository, $paymentRequestAnnouncer, PaymentRequestInterface::ACTION_REFUND);
        $listener(new CompletedEvent($payment, new Marking()));
    }
}
