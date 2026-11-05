<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Config\Environment;

final class ModelTest extends TestCase
{
    public function test_transaction_statuses(): void
    {
        self::assertSame([false, true, true, true], [
            Transaction::isPaidStatus(Transaction::STATUS_NO_PAYMENT),
            Transaction::isPaidStatus(Transaction::STATUS_ADVANCE_PAYMENT),
            Transaction::isPaidStatus(Transaction::STATUS_PAYMENT_MADE),
            Transaction::isPaidStatus(Transaction::STATUS_PAYMENT_RETURNED),
        ]);
        self::assertSame([false, false, true, true, false], [
            Transaction::isFinalStatus(Transaction::STATUS_NO_PAYMENT),
            Transaction::isFinalStatus(Transaction::STATUS_ADVANCE_PAYMENT),
            Transaction::isFinalStatus(Transaction::STATUS_PAYMENT_MADE),
            Transaction::isFinalStatus(Transaction::STATUS_PAYMENT_RETURNED),
            Transaction::isFinalStatus(null),
        ]);

        $verified = (new Transaction('s-1', 7, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN'))->withStatus(Transaction::STATUS_PAYMENT_MADE);
        self::assertEquals(new Transaction('s-1', 7, Transaction::STATUS_PAYMENT_MADE, 1500, 'PLN'), $verified);
    }

    public function test_refund_statuses(): void
    {
        self::assertSame(Refund::STATUS_COMPLETED, Refund::fromNotificationStatus(Refund::NOTIFICATION_STATUS_COMPLETED));
        self::assertSame(Refund::STATUS_REJECTED, Refund::fromNotificationStatus(Refund::NOTIFICATION_STATUS_REJECTED));
        self::assertNull(Refund::fromNotificationStatus(2));

        self::assertTrue(Refund::isFinalStatus(Refund::STATUS_COMPLETED));
        self::assertTrue(Refund::isFinalStatus(Refund::STATUS_REJECTED));
        self::assertFalse(Refund::isFinalStatus(Refund::STATUS_PENDING));
        self::assertFalse(Refund::isFinalStatus(null));
    }

    public function test_api_exception_classification(): void
    {
        self::assertTrue((new Przelewy24ApiException('x', 404))->isNotFound());
        self::assertFalse((new Przelewy24ApiException('x', 400))->isNotFound());
        self::assertTrue((new Przelewy24ApiException('x', 401))->isAuthenticationFailure());
        self::assertTrue((new Przelewy24ApiException('x', 403))->isAuthenticationFailure());
        self::assertFalse((new Przelewy24ApiException('x', 404))->isAuthenticationFailure());
        self::assertFalse((new Przelewy24ApiException('x', 499))->isTransient());
    }

    public function test_environment_hosts(): void
    {
        self::assertSame('https://sandbox.przelewy24.pl', Environment::Sandbox->baseUrl());
        self::assertSame('https://secure.przelewy24.pl', Environment::Production->baseUrl());
    }
}
