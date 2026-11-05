<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Integration;

use Tests\Refberg\SyliusPrzelewy24Plugin\HeadlessApplication\HeadlessKernel;

/**
 * Application without the shop, the admin panel and Twig Hooks.
 */
final class HeadlessApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_checkout_is_paid_by_a_signed_notification_without_the_shop(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame('processing', $created['state'], (string) $this->client->getResponse()->getContent());
        self::assertSame('http://localhost/payment-methods/P24', $this->p24->transaction($this->p24->lastSessionId())['urlStatus']);

        $this->payAndNotify();
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_capture_without_continue_url_fails_without_calling_przelewy24(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
        ]);

        self::assertSame('failed', $created['state']);
        self::assertIsString(self::at($created, 'responseData', 'error'));
        self::assertStringContainsString('continue_url', self::at($created, 'responseData', 'error'));
        self::assertSame([], $this->p24->requests());
    }

    public function test_container_has_no_shop_or_admin_services(): void
    {
        $container = self::getContainer();

        self::assertFalse($container->has('sylius_shop.provider.order_pay.after_pay_url'));
        self::assertFalse($container->has('sylius_twig_hooks.registry.hooks'));
    }

    protected static function getKernelClass(): string
    {
        return HeadlessKernel::class;
    }
}
