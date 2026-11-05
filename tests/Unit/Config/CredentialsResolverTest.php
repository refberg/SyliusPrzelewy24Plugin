<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Config;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\Config\Environment;
use Refberg\SyliusPrzelewy24Plugin\Config\InvalidConfigurationException;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use function print_r;
use function serialize;
use function str_replace;

final class CredentialsResolverTest extends TestCase
{
    public function test_it_resolves_credentials(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(Przelewy24Gateway::FACTORY_NAME, self::config()));

        self::assertSame(123456, $credentials->merchantId);
        self::assertSame(654321, $credentials->posId);
        self::assertSame('crc', $credentials->crcKey);
        self::assertSame('api', $credentials->apiKey);
        self::assertSame(Environment::Sandbox, $credentials->environment);
    }

    public function test_empty_pos_id_defaults_to_the_merchant_id(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(Przelewy24Gateway::FACTORY_NAME, ['pos_id' => ' '] + self::config()));

        self::assertSame(123456, $credentials->posId);
    }

    public function test_it_supports_only_the_przelewy24_factory(): void
    {
        $resolver = new CredentialsResolver();

        self::assertTrue($resolver->supports(self::method(Przelewy24Gateway::FACTORY_NAME, [])));
        self::assertTrue($resolver->supports(self::method('other', ['factory' => Przelewy24Gateway::FACTORY_NAME])));
        self::assertFalse($resolver->supports(self::method('offline', [])));
        self::assertFalse($resolver->supports(new PaymentMethod()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown environment' => [['environment' => 'staging'] + self::config()];
        yield 'missing merchant ID' => [['merchant_id' => ''] + self::config()];
        yield 'non-numeric merchant ID' => [['merchant_id' => '12a'] + self::config()];
        yield 'zero merchant ID' => [['merchant_id' => '0'] + self::config()];
        yield 'too long merchant ID' => [['merchant_id' => '1234567890123456789'] + self::config()];
        yield 'non-numeric POS ID' => [['pos_id' => '-1'] + self::config()];
        yield 'missing CRC key' => [['crc_key' => ''] + self::config()];
        yield 'missing API key' => [['api_key' => ['x']] + self::config()];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function test_it_rejects_invalid_configuration(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method(Przelewy24Gateway::FACTORY_NAME, $config));
    }

    public function test_it_rejects_another_gateway(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method('offline', self::config()));
    }

    public function test_secrets_stay_out_of_debug_output_and_serialization(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(Przelewy24Gateway::FACTORY_NAME, self::config()));

        $output = print_r($credentials, true);
        self::assertStringNotContainsString('crc', str_replace('crcKey', '', $output));
        self::assertStringNotContainsString('api', str_replace('apiKey', '', $output));

        $this->expectException(LogicException::class);
        serialize($credentials);
    }

    /**
     * @return array<string, string>
     */
    private static function config(): array
    {
        return ['environment' => 'sandbox', 'merchant_id' => '123456', 'pos_id' => '654321', 'crc_key' => 'crc', 'api_key' => 'api'];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function method(string $factory, array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factory);
        $gatewayConfig->setConfig($config);
        $method = new PaymentMethod();
        $method->setCode('P24');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }
}
