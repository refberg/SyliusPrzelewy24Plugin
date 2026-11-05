<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Config\Environment;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Notification\Notification;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParser;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class NotificationParserTest extends TestCase
{
    public function test_signed_transaction_notification_is_parsed(): void
    {
        $notification = self::parser()->parse(self::transactionBody(), self::credentials());

        self::assertEquals(new Notification(Notification::TYPE_TRANSACTION, 'order-1-abcdef012345', 3000000001, 1500, 'PLN'), $notification);
    }

    public function test_order_id_above_the_32_bit_range_is_kept_exactly(): void
    {
        $notification = self::parser()->parse(self::transactionBody(['orderId' => 9007199254740993]), self::credentials());

        self::assertSame(9007199254740993, $notification?->orderId);
    }

    public function test_transaction_notification_of_another_merchant_or_shop_is_rejected(): void
    {
        self::assertNull(self::parser()->parse(self::transactionBody(['merchantId' => 999999]), self::credentials()));
        self::assertNull(self::parser()->parse(self::transactionBody(['posId' => 999999]), self::credentials()));
    }

    public function test_shop_id_different_from_the_merchant_id_is_supported(): void
    {
        $credentials = new Przelewy24Credentials(123456, 654321, FakePrzelewy24::CRC_KEY, FakePrzelewy24::API_KEY, Environment::Sandbox);

        self::assertInstanceOf(Notification::class, self::parser()->parse(self::transactionBody(['posId' => 654321]), $credentials));
    }

    public function test_signed_refund_notification_is_parsed(): void
    {
        $notification = self::parser()->parse(self::refundBody(Refund::NOTIFICATION_STATUS_REJECTED), self::credentials());

        self::assertEquals(new Notification(Notification::TYPE_REFUND, 'order-1-abcdef012345', 3000000001, 1500, 'PLN', 'uuid1', Refund::STATUS_REJECTED), $notification);
    }

    public function test_refund_notification_with_an_unknown_status_or_merchant_is_rejected(): void
    {
        self::assertNull(self::parser()->parse(self::refundBody(7), self::credentials()));
        self::assertNull(self::parser()->parse(self::refundBody(0, ['merchantId' => 999999]), self::credentials()));
        self::assertNull(self::parser()->parse(self::refundBody(0, ['refundsUuid' => '']), self::credentials()));
    }

    public function test_bodies_that_are_not_signed_json_objects_are_rejected(): void
    {
        foreach (['', 'null', '"text"', '[1, 2]', '{', '{"sign": "x"}'] as $body) {
            self::assertNull(self::parser()->parse($body, self::credentials()), $body);
        }

        self::assertNull(self::parser()->parse(self::transactionBody(), new Przelewy24Credentials(123456, 123456, 'other', 'key', Environment::Sandbox)));
    }

    public function test_stored_payload_is_parsed_from_the_http_request_content(): void
    {
        $parser = self::parser();

        self::assertInstanceOf(Notification::class, $parser->parseStoredPayload(['http_request' => ['content' => self::transactionBody()]], self::credentials()));
        self::assertNull($parser->parseStoredPayload(['http_request' => ['content' => null]], self::credentials()));
        self::assertNull($parser->parseStoredPayload(['http_request' => 'text'], self::credentials()));
        self::assertNull($parser->parseStoredPayload(null, self::credentials()));
    }

    private static function parser(): NotificationParser
    {
        return new NotificationParser(new SignatureVerifier());
    }

    private static function credentials(): Przelewy24Credentials
    {
        return new Przelewy24Credentials(FakePrzelewy24::MERCHANT_ID, FakePrzelewy24::POS_ID, FakePrzelewy24::CRC_KEY, FakePrzelewy24::API_KEY, Environment::Sandbox);
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private static function transactionBody(array $overrides = []): string
    {
        $fields = $overrides + [
            'merchantId' => 123456,
            'posId' => 123456,
            'sessionId' => 'order-1-abcdef012345',
            'amount' => 1500,
            'originAmount' => 1500,
            'currency' => 'PLN',
            'orderId' => 3000000001,
            'methodId' => 25,
            'statement' => 'p24-statement',
        ];
        $ordered = [];
        foreach (['merchantId', 'posId', 'sessionId', 'amount', 'originAmount', 'currency', 'orderId', 'methodId', 'statement'] as $key) {
            $ordered[$key] = $fields[$key];
        }
        $ordered['sign'] = FakePrzelewy24::sign($ordered);

        return json_encode($ordered, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private static function refundBody(int $status, array $overrides = []): string
    {
        $fields = $overrides + [
            'orderId' => 3000000001,
            'sessionId' => 'order-1-abcdef012345',
            'refundsUuid' => 'uuid1',
            'merchantId' => 123456,
            'amount' => 1500,
            'currency' => 'PLN',
            'status' => $status,
        ];
        $ordered = [];
        foreach (['orderId', 'sessionId', 'refundsUuid', 'merchantId', 'amount', 'currency', 'status'] as $key) {
            $ordered[$key] = $fields[$key];
        }

        return json_encode($ordered + ['requestId' => 'request-1', 'timestamp' => 1767225600, 'sign' => FakePrzelewy24::sign($ordered)], JSON_THROW_ON_ERROR);
    }
}
