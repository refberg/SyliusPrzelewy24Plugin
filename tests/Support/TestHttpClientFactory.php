<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client used by the plugin in tests.
 *
 * By default every request goes to FakePrzelewy24. Only with P24_SANDBOX=1 (manual sandbox tests)
 * a real client is used, restricted to the Przelewy24 sandbox host: production is never reachable from tests.
 */
final class TestHttpClientFactory
{
    public static function create(FakePrzelewy24 $fake): HttpClientInterface
    {
        if (!SandboxEnvironment::isEnabled()) {
            return new MockHttpClient($fake, 'https://' . FakePrzelewy24::HOST);
        }

        return new SandboxOnlyHttpClient(HttpClient::create());
    }
}
