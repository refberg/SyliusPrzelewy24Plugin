<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\DependencyInjection;

use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use function array_key_exists;
use function sprintf;

final class RefbergSyliusPrzelewy24Extension extends Extension implements PrependExtensionInterface
{
    public const SHOP_BUNDLE = 'SyliusShopBundle';

    public const TWIG_HOOKS_EXTENSION = 'sylius_twig_hooks';

    public const GATEWAY_CONFIGURATION_TEMPLATE = '@RefbergSyliusPrzelewy24Plugin/admin/payment_method/gateway_configuration.html.twig';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        if (array_key_exists(self::SHOP_BUNDLE, $bundles)) {
            $loader->load('services/integrations/sylius_shop.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension(self::TWIG_HOOKS_EXTENSION)) {
            return;
        }

        $gatewayConfiguration = [
            Przelewy24Gateway::FACTORY_NAME => [
                'template' => self::GATEWAY_CONFIGURATION_TEMPLATE,
                'priority' => 0,
            ],
        ];
        $hookPrefix = 'sylius_admin.payment_method.%s.content.form.sections.gateway_configuration.' . Przelewy24Gateway::FACTORY_NAME;

        // Prepended, so the application configuration can still override the plugin hooks.
        $container->prependExtensionConfig(self::TWIG_HOOKS_EXTENSION, ['hooks' => [
            sprintf($hookPrefix, 'create') => $gatewayConfiguration,
            sprintf($hookPrefix, 'update') => $gatewayConfiguration,
        ]]);
    }

    public function getAlias(): string
    {
        return 'refberg_sylius_przelewy24';
    }
}
