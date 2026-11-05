<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\TestHttpClientFactory;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    if (!str_starts_with((string) $container->env(), 'test')) {
        return;
    }

    $services = $container->services();

    // Przelewy24 traffic in tests goes to an in-memory fake of the Przelewy24 sandbox; FakePrzelewy24 rejects
    // any other host. Only P24_SANDBOX=1 switches to the real sandbox (see tests/Sandbox).
    $services->set(FakePrzelewy24::class)->public();

    $services->set('refberg_przelewy24.http_client', HttpClientInterface::class)
        ->factory([TestHttpClientFactory::class, 'create'])
        ->args([service(FakePrzelewy24::class)])
        ->public();
};
