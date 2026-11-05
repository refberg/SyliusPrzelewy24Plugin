<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Order;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGenerator;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use function str_repeat;
use function strlen;

final class SessionIdGeneratorTest extends TestCase
{
    public function test_session_id_contains_the_order_number_the_payment_id_and_the_request_hash(): void
    {
        $sessionId = (new SessionIdGenerator())->generate(self::payment(42, 'SO/2026/001'), self::request('3F2504E0-4F89-11D3-9A0C-0305E82C3301'));

        self::assertSame('SO2026001-42-3f2504e04f89', $sessionId);
        self::assertSame(42, (new SessionIdGenerator())->extractPaymentId($sessionId));
    }

    public function test_long_or_empty_order_numbers_keep_the_session_id_short(): void
    {
        $generator = new SessionIdGenerator();

        $long = $generator->generate(self::payment(7, str_repeat('9', 120)), self::request('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        self::assertSame(40 + strlen('-7-3f2504e04f89'), strlen($long));
        self::assertSame(7, $generator->extractPaymentId($long));

        self::assertSame('order-7-3f2504e04f89', $generator->generate(self::payment(7, '///'), self::request('3f2504e0-4f89-11d3-9a0c-0305e82c3301')));
    }

    #[TestWith(['order-1'])]
    #[TestWith(['order-x-3f2504e04f89'])]
    #[TestWith(['order-1-3F2504E04F89'])]
    #[TestWith(['order-1-3f2504e04f89-suffix'])]
    #[TestWith(['order-1234567890123456789-3f2504e04f89'])]
    public function test_foreign_session_ids_have_no_payment_id(string $sessionId): void
    {
        self::assertNull((new SessionIdGenerator())->extractPaymentId($sessionId));
    }

    public function test_unsaved_payment_cannot_get_a_session_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SessionIdGenerator())->generate(new Payment(), self::request('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
    }

    private static function payment(int $id, string $number): Payment
    {
        $payment = new class($id) extends Payment {
            public function __construct(private readonly int $fixedId)
            {
                parent::__construct();
            }

            public function getId(): int
            {
                return $this->fixedId;
            }
        };
        $order = new Order();
        $order->setNumber($number);
        $payment->setOrder($order);

        return $payment;
    }

    private static function request(string $hash): PaymentRequest
    {
        return new class($hash) extends PaymentRequest {
            public function __construct(private readonly string $fixedHash)
            {
                parent::__construct(new Payment(), new PaymentMethod());
            }

            public function getId(): string
            {
                return $this->fixedHash;
            }
        };
    }
}
