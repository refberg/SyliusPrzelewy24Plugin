<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Model;

final readonly class TransactionRegistered
{
    public function __construct(
        public string $token,
        public string $redirectUrl,
    ) {
    }
}
