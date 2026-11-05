<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Config;

use LogicException;
use SensitiveParameter;

final readonly class Przelewy24Credentials
{
    /**
     * @param int $merchantId Przelewy24 merchant ID ("merchantId")
     * @param int $posId shop ID ("posId"); the merchant ID unless the account has several shops
     * @param string $crcKey CRC key used for the "sign" checksums
     * @param string $apiKey API key ("secretId", the "reports key"), the password of Basic authentication
     */
    public function __construct(
        public int $merchantId,
        public int $posId,
        #[SensitiveParameter]
        public string $crcKey,
        #[SensitiveParameter]
        public string $apiKey,
        public Environment $environment,
    ) {
    }

    /**
     * Keeps secrets out of var_dump(), dump() and debug output.
     *
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return [
            'merchantId' => $this->merchantId,
            'posId' => $this->posId,
            'crcKey' => '***',
            'apiKey' => '***',
            'environment' => $this->environment->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('Przelewy24 credentials must not be serialized.');
    }
}
