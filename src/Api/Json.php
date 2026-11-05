<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api;

use function ctype_digit;
use function is_int;
use function is_string;

/**
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : null;
    }
}
