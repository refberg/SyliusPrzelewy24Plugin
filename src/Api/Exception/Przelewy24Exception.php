<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api\Exception;

use RuntimeException;

/**
 * Base exception for Przelewy24 communication problems.
 *
 * Messages never contain credentials or raw request bodies,
 * so they are safe to log and to store in PaymentRequest::$responseData.
 */
class Przelewy24Exception extends RuntimeException
{
}
