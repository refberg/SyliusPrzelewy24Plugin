<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Notification;

use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;

/**
 * parse() and parseStoredPayload() return null for anything that is not a correctly signed, well-formed notification
 * of the merchant and shop of the given credentials.
 */
interface NotificationParserInterface
{
    public function parse(string $rawBody, Przelewy24Credentials $credentials): ?Notification;

    public function parseStoredPayload(mixed $payload, Przelewy24Credentials $credentials): ?Notification;
}
