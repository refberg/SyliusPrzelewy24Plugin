<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Signature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use function array_diff_key;
use function strtoupper;

final class SignatureVerifierTest extends TestCase
{
    private const CRC = 'test-crc-key';

    /** Checksum of {"merchantId":123456,...,"statement":"p24-ś/1","crc":"test-crc-key"} computed independently (Python hashlib). */
    private const NOTIFICATION_SIGN = '00e9c3f3732504af2c109663e91c2fae863fcc808c17408fb03dd8005d0267ed2ba393dc1df634d073249e2c26753f47';

    public function test_sign_matches_the_documented_registration_example(): void
    {
        // {"sessionId":"sessionId","merchantId":999999,"amount":1000,"currency":"PLN","crc":"crc"} hashed with SHA-384.
        self::assertSame(
            'a34cf822f00b51d8d75e2d21334d6ffb5f2fac8408574f4cd7d4897f07ed61312244f9875e18ce63a4fbe2668d7ffc30',
            (new SignatureVerifier())->sign(['sessionId' => 'sessionId', 'merchantId' => 999999, 'amount' => 1000, 'currency' => 'PLN'], 'crc'),
        );
    }

    public function test_transaction_notification_with_a_correct_sign_is_accepted(): void
    {
        $verifier = new SignatureVerifier();

        self::assertTrue($verifier->verifyTransactionNotification(self::notification(), self::CRC));
        // Upper-case hex and integers sent as strings are accepted too.
        self::assertTrue($verifier->verifyTransactionNotification(['sign' => strtoupper(self::NOTIFICATION_SIGN), 'amount' => '1500'] + self::notification(), self::CRC));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidNotifications(): iterable
    {
        yield 'changed amount' => [['amount' => 1] + self::notification()];
        yield 'changed session' => [['sessionId' => 'other'] + self::notification()];
        yield 'missing field' => [array_diff_key(self::notification(), ['statement' => true])];
        yield 'missing sign' => [array_diff_key(self::notification(), ['sign' => true])];
        yield 'empty sign' => [['sign' => ''] + self::notification()];
        yield 'non-string sign' => [['sign' => 42] + self::notification()];
        yield 'amount of wrong type' => [['amount' => 1500.0] + self::notification()];
        yield 'negative amount string' => [['amount' => '-1500'] + self::notification()];
        yield 'statement of wrong type' => [['statement' => ['p24-ś/1']] + self::notification()];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidNotifications')]
    public function test_transaction_notification_with_a_wrong_sign_or_body_is_rejected(array $data): void
    {
        self::assertFalse((new SignatureVerifier())->verifyTransactionNotification($data, self::CRC));
    }

    public function test_notification_is_rejected_with_another_or_empty_crc_key(): void
    {
        $verifier = new SignatureVerifier();

        self::assertFalse($verifier->verifyTransactionNotification(self::notification(), 'other-crc-key'));
        self::assertFalse($verifier->verifyTransactionNotification(self::notification(), ''));
    }

    public function test_refund_notification_sign_covers_the_documented_fields_in_order(): void
    {
        $verifier = new SignatureVerifier();
        $signed = [
            'orderId' => 3000000001,
            'sessionId' => 'order-1-abcdef012345',
            'refundsUuid' => '0123456789abcdef0123456789abcdef',
            'merchantId' => 123456,
            'amount' => 1500,
            'currency' => 'PLN',
            'status' => 0,
        ];
        $data = $signed + ['requestId' => 'not-signed', 'timestamp' => 1767225600, 'sign' => $verifier->sign($signed, self::CRC)];

        self::assertTrue($verifier->verifyRefundNotification($data, self::CRC));
        self::assertTrue($verifier->verifyRefundNotification(['requestId' => 'changed'] + $data, self::CRC));
        self::assertFalse($verifier->verifyRefundNotification(['status' => 1] + $data, self::CRC));
        self::assertFalse($verifier->verifyTransactionNotification($data, self::CRC));
    }

    /**
     * @return array<string, mixed>
     */
    private static function notification(): array
    {
        return [
            'merchantId' => 123456,
            'posId' => 123456,
            'sessionId' => 'zamówienie/1',
            'amount' => 1500,
            'originAmount' => 1500,
            'currency' => 'PLN',
            'orderId' => 3000000001,
            'methodId' => 25,
            'statement' => 'p24-ś/1',
            'sign' => self::NOTIFICATION_SIGN,
        ];
    }
}
