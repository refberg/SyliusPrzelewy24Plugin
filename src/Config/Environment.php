<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Config;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sandbox.przelewy24.pl',
            self::Production => 'https://secure.przelewy24.pl',
        };
    }
}
