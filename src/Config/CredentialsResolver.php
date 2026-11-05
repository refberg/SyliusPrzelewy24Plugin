<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Config;

use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use function ctype_digit;
use function is_scalar;
use function sprintf;
use function strlen;
use function trim;

final readonly class CredentialsResolver implements CredentialsResolverInterface
{
    /** Przelewy24 IDs are positive integers; 18 digits keep them within a 64-bit integer. */
    private const MAX_ID_LENGTH = 18;

    public function supports(PaymentMethodInterface $paymentMethod): bool
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return false;
        }

        $factory = $gatewayConfig->getConfig()['factory'] ?? $gatewayConfig->getFactoryName();

        return Przelewy24Gateway::FACTORY_NAME === $factory;
    }

    public function resolve(PaymentMethodInterface $paymentMethod): Przelewy24Credentials
    {
        $code = (string) $paymentMethod->getCode();
        if (!$this->supports($paymentMethod)) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" is not a Przelewy24 payment method.', $code));
        }

        /** @var array<string, mixed> $config */
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];

        $environment = Environment::tryFrom(self::string($config, Przelewy24Gateway::CONFIG_ENVIRONMENT));
        if (null === $environment) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" has no valid Przelewy24 environment.', $code));
        }

        $merchantId = self::id($config, Przelewy24Gateway::CONFIG_MERCHANT_ID, $code, true);
        $posId = self::id($config, Przelewy24Gateway::CONFIG_POS_ID, $code, false) ?? $merchantId;

        $crcKey = self::string($config, Przelewy24Gateway::CONFIG_CRC_KEY);
        $apiKey = self::string($config, Przelewy24Gateway::CONFIG_API_KEY);
        foreach ([Przelewy24Gateway::CONFIG_CRC_KEY => $crcKey, Przelewy24Gateway::CONFIG_API_KEY => $apiKey] as $field => $value) {
            if ('' === $value) {
                throw new InvalidConfigurationException(sprintf('Payment method "%s" is missing the Przelewy24 "%s" setting.', $code, $field));
            }
        }

        return new Przelewy24Credentials($merchantId, $posId, $crcKey, $apiKey, $environment);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return ($required is true ? int : int|null)
     */
    private static function id(array $config, string $key, string $code, bool $required): ?int
    {
        $value = self::string($config, $key);
        if ('' === $value && !$required) {
            return null;
        }

        if (!ctype_digit($value) || strlen($value) > self::MAX_ID_LENGTH || 0 === (int) $value) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" has no valid Przelewy24 "%s" setting.', $code, $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
