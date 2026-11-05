<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Exception;

use function in_array;

/**
 * Przelewy24 answered, but with an error (4xx/5xx or an unexpected response).
 */
final class Przelewy24ApiException extends Przelewy24Exception
{
    public function __construct(
        string $message,
        private readonly int $httpStatusCode,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function isTransient(): bool
    {
        return $this->httpStatusCode >= 500 || in_array($this->httpStatusCode, [408, 429], true);
    }

    /**
     * Przelewy24 rejected the merchant credentials (POS ID or API key) or the API is not enabled for the shop.
     */
    public function isAuthenticationFailure(): bool
    {
        return in_array($this->httpStatusCode, [401, 403], true);
    }

    public function isNotFound(): bool
    {
        return 404 === $this->httpStatusCode;
    }
}
