<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\DependencyInjection\RefbergSyliusPrzelewy24Extension;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidatorInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface;
use Sylius\Bundle\ShopBundle\SyliusShopBundle;
use Sylius\TwigHooks\DependencyInjection\SyliusTwigHooksExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RefbergSyliusPrzelewy24ExtensionTest extends TestCase
{
    public function test_every_injected_service_is_registered_under_its_interface(): void
    {
        $container = self::load([]);

        foreach ([
            Przelewy24ClientInterface::class,
            CredentialsResolverInterface::class,
            SignatureVerifierInterface::class,
            NotificationParserInterface::class,
            SessionIdGeneratorInterface::class,
            TransactionRequestFactoryInterface::class,
            TransactionValidatorInterface::class,
            PaymentLockerInterface::class,
            PaymentStateApplierInterface::class,
            PaymentSynchronizerInterface::class,
        ] as $id) {
            self::assertTrue($container->hasDefinition($id), $id);
            self::assertFalse($container->getDefinition($id)->isPublic(), $id);
        }

        self::assertSame(15.0, $container->getParameter('refberg_przelewy24.http_timeout'));
    }

    public function test_shop_services_are_registered_with_the_shop_bundle(): void
    {
        $container = self::load(['SyliusShopBundle' => SyliusShopBundle::class]);

        $afterPayUrlProvider = $container->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(1);
        self::assertInstanceOf(Reference::class, $afterPayUrlProvider);
        self::assertSame('sylius_shop.provider.order_pay.after_pay_url', (string) $afterPayUrlProvider);
    }

    public function test_shop_services_are_not_registered_without_the_shop_bundle(): void
    {
        self::assertNull(self::load([])->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(1));
    }

    public function test_refund_and_cancel_listeners_listen_to_the_workflow_completed_events(): void
    {
        $container = self::load([]);

        foreach (['refund', 'cancel'] as $transition) {
            $tags = $container->getDefinition('refberg_przelewy24.listener.payment_transition.' . $transition)->getTag('kernel.event_listener');
            self::assertSame([['event' => 'workflow.sylius_payment.completed.' . $transition]], $tags);
        }
    }

    public function test_admin_twig_hooks_are_prepended_when_twig_hooks_are_enabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SyliusTwigHooksExtension());

        (new RefbergSyliusPrzelewy24Extension())->prepend($container);

        $gatewayConfiguration = [
            'refberg_przelewy24' => [
                'template' => '@RefbergSyliusPrzelewy24Plugin/admin/payment_method/gateway_configuration.html.twig',
                'priority' => 0,
            ],
        ];
        self::assertSame([['hooks' => [
            'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.refberg_przelewy24' => $gatewayConfiguration,
            'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.refberg_przelewy24' => $gatewayConfiguration,
        ]]], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function test_no_twig_hooks_are_configured_without_twig_hooks(): void
    {
        $container = new ContainerBuilder();

        (new RefbergSyliusPrzelewy24Extension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    /**
     * @param array<string, class-string> $bundles
     */
    private static function load(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);

        (new RefbergSyliusPrzelewy24Extension())->load([], $container);

        return $container;
    }
}
