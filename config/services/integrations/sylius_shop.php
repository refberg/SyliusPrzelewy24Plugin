<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->private();

    // Without the shop, API clients must send "continue_url" in the capture payload.
    $services->get(TransactionRequestFactoryInterface::class)
        ->arg(1, service('sylius_shop.provider.order_pay.after_pay_url'));
};
