<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Signature;

use SensitiveParameter;

/**
 * Calculates and verifies the Przelewy24 "sign" checksum: SHA-384 of the JSON object of the listed fields,
 * in the documented order, followed by "crc" (the CRC key).
 */
interface SignatureVerifierInterface
{
    /**
     * @param array<string, int|string> $fields fields in the order required by the request
     */
    public function sign(array $fields, #[SensitiveParameter] string $crcKey): string;

    /**
     * Verifies the "sign" of a transaction notification (urlStatus of transaction/register).
     *
     * @param array<array-key, mixed> $data decoded notification body
     */
    public function verifyTransactionNotification(array $data, #[SensitiveParameter] string $crcKey): bool;

    /**
     * Verifies the "sign" of a refund notification (urlStatus of transaction/refund).
     *
     * @param array<array-key, mixed> $data decoded notification body
     */
    public function verifyRefundNotification(array $data, #[SensitiveParameter] string $crcKey): bool;
}
