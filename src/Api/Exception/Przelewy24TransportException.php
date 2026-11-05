<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Exception;

/**
 * Network-level failure (timeout, DNS, TLS). Always safe to retry.
 */
final class Przelewy24TransportException extends Przelewy24Exception
{
}
