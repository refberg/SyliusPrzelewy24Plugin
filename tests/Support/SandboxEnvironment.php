<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Support;

use function getenv;
use function is_string;

/**
 * Settings of the optional tests against the real Przelewy24 sandbox, read from environment variables.
 * Credentials are never stored in the repository.
 */
final class SandboxEnvironment
{
    private const CREDENTIAL_VARIABLES = [
        'merchant_id' => 'P24_SANDBOX_MERCHANT_ID',
        'crc_key' => 'P24_SANDBOX_CRC_KEY',
        'api_key' => 'P24_SANDBOX_API_KEY',
    ];

    public static function isEnabled(): bool
    {
        return '1' === self::value('P24_SANDBOX');
    }

    /**
     * @return array<string, string>|null
     */
    public static function credentials(): ?array
    {
        $credentials = [];
        foreach (self::CREDENTIAL_VARIABLES as $key => $variable) {
            $value = self::value($variable);
            if (null === $value) {
                return null;
            }

            $credentials[$key] = $value;
        }

        $credentials['pos_id'] = self::value('P24_SANDBOX_POS_ID') ?? '';

        return $credentials;
    }

    private static function value(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
