<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Signature;

use JsonException;
use SensitiveParameter;
use function ctype_digit;
use function hash;
use function hash_equals;
use function is_int;
use function is_string;
use function json_encode;
use function strtolower;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class SignatureVerifier implements SignatureVerifierInterface
{
    private const TYPE_INT = 'int';

    private const TYPE_STRING = 'string';

    /** Fields of the transaction notification "sign", in order. */
    public const TRANSACTION_NOTIFICATION_FIELDS = [
        'merchantId' => self::TYPE_INT,
        'posId' => self::TYPE_INT,
        'sessionId' => self::TYPE_STRING,
        'amount' => self::TYPE_INT,
        'originAmount' => self::TYPE_INT,
        'currency' => self::TYPE_STRING,
        'orderId' => self::TYPE_INT,
        'methodId' => self::TYPE_INT,
        'statement' => self::TYPE_STRING,
    ];

    /** Fields of the refund notification "sign", in order. */
    public const REFUND_NOTIFICATION_FIELDS = [
        'orderId' => self::TYPE_INT,
        'sessionId' => self::TYPE_STRING,
        'refundsUuid' => self::TYPE_STRING,
        'merchantId' => self::TYPE_INT,
        'amount' => self::TYPE_INT,
        'currency' => self::TYPE_STRING,
        'status' => self::TYPE_INT,
    ];

    private const HASH_ALGORITHM = 'sha384';

    public function sign(array $fields, #[SensitiveParameter] string $crcKey): string
    {
        $fields['crc'] = $crcKey;

        return hash(self::HASH_ALGORITHM, json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function verifyTransactionNotification(array $data, #[SensitiveParameter] string $crcKey): bool
    {
        return $this->verify($data, self::TRANSACTION_NOTIFICATION_FIELDS, $crcKey);
    }

    public function verifyRefundNotification(array $data, #[SensitiveParameter] string $crcKey): bool
    {
        return $this->verify($data, self::REFUND_NOTIFICATION_FIELDS, $crcKey);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, self::TYPE_*> $definition
     */
    private function verify(array $data, array $definition, string $crcKey): bool
    {
        $signature = $data['sign'] ?? null;
        if ('' === $crcKey || !is_string($signature) || '' === $signature) {
            return false;
        }

        $fields = [];
        foreach ($definition as $name => $type) {
            $value = self::typed($data[$name] ?? null, $type);
            if (null === $value) {
                return false;
            }

            $fields[$name] = $value;
        }

        try {
            $expected = $this->sign($fields, $crcKey);
        } catch (JsonException) {
            return false;
        }

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Przelewy24 signs integers as JSON numbers and strings as JSON strings, so the type decides the checksum.
     *
     * @param self::TYPE_* $type
     */
    private static function typed(mixed $value, string $type): int|string|null
    {
        if (self::TYPE_INT === $type) {
            if (is_int($value)) {
                return $value;
            }

            return is_string($value) && '' !== $value && ctype_digit($value) ? (int) $value : null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : null;
    }
}
