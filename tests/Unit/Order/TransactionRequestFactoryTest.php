<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Order;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactory;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function is_string;
use function str_repeat;

final class TransactionRequestFactoryTest extends TestCase
{
    public function test_body_is_built_from_the_sylius_payment(): void
    {
        [$request, $payment] = self::payment();

        $body = self::factory()->create($request, $payment, 'session-1');

        self::assertSame([
            'sessionId' => 'session-1',
            'amount' => 1500,
            'currency' => 'PLN',
            'description' => 'Order 000000001',
            'email' => 'buyer@example.com',
            'country' => 'DE',
            'language' => 'de',
            'urlReturn' => 'https://shop.example/after-pay',
            'urlStatus' => 'https://shop.example/payment-methods/P24',
            'client' => 'Jan Kowalski',
            'address' => 'Długa 1',
            'zip' => '00-001',
            'city' => 'Warszawa',
        ], $body);
    }

    public function test_continue_url_from_the_payload_replaces_the_after_pay_url(): void
    {
        [$request, $payment] = self::payment();
        $request->setPayload(['continue_url' => 'https://app.example/return']);

        self::assertSame('https://app.example/return', self::factory()->create($request, $payment, 'session-1')['urlReturn']);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['/relative'])]
    #[TestWith(['ftp://shop.example/'])]
    public function test_continue_url_must_be_an_absolute_http_url(string $url): void
    {
        [$request, $payment] = self::payment();
        $request->setPayload(['continue_url' => $url]);

        $this->expectException(InvalidArgumentException::class);

        self::factory()->create($request, $payment, 'session-1');
    }

    public function test_return_url_longer_than_250_characters_is_rejected(): void
    {
        [$request, $payment] = self::payment();
        $request->setPayload(['continue_url' => 'https://app.example/' . str_repeat('a', 240)]);

        $this->expectExceptionMessage('"urlReturn" must be at most 250 characters long');

        self::factory()->create($request, $payment, 'session-1');
    }

    public function test_without_the_shop_the_continue_url_is_required(): void
    {
        [$request, $payment] = self::payment();

        $this->expectExceptionMessage('Payload "continue_url" is required when the shop is not installed.');

        (new TransactionRequestFactory(self::router(), null))->create($request, $payment, 'session-1');
    }

    public function test_unknown_locale_falls_back_to_english_and_missing_address_to_poland(): void
    {
        [$request, $payment] = self::payment();
        $order = $payment->getOrder();
        self::assertInstanceOf(Order::class, $order);
        $order->setLocaleCode('ja_JP');
        $order->setBillingAddress(new Address());

        $body = self::factory()->create($request, $payment, 'session-1');

        self::assertSame('en', $body['language']);
        self::assertSame('PL', $body['country']);
        self::assertArrayNotHasKey('client', $body);
    }

    public function test_long_customer_data_is_cut_to_the_przelewy24_limits(): void
    {
        [$request, $payment] = self::payment();
        $address = $payment->getOrder()?->getBillingAddress();
        self::assertInstanceOf(Address::class, $address);
        $address->setFirstName(str_repeat('Ą', 30));
        $address->setLastName(str_repeat('B', 30));
        $address->setCity(str_repeat('C', 60));

        $body = self::factory()->create($request, $payment, 'session-1');

        self::assertSame(str_repeat('Ą', 30) . ' ' . str_repeat('B', 9), $body['client']);
        self::assertSame(str_repeat('C', 50), $body['city']);
    }

    #[TestWith([''])]
    #[TestWith(['not-an-email'])]
    public function test_valid_customer_email_is_required(string $email): void
    {
        [$request, $payment] = self::payment();
        $payment->getOrder()?->getCustomer()?->setEmail($email);

        $this->expectException(InvalidArgumentException::class);

        self::factory()->create($request, $payment, 'session-1');
    }

    public function test_payment_without_amount_is_rejected(): void
    {
        [$request, $payment] = self::payment();
        $payment->setAmount(0);

        $this->expectException(InvalidArgumentException::class);

        self::factory()->create($request, $payment, 'session-1');
    }

    private static function factory(): TransactionRequestFactory
    {
        $afterPay = self::createStub(UrlProviderInterface::class);
        $afterPay->method('getUrl')->willReturn('https://shop.example/after-pay');

        return new TransactionRequestFactory(self::router(), $afterPay);
    }

    private static function router(): UrlGeneratorInterface
    {
        $router = self::createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://shop.example/payment-methods/' . (is_string($parameters['code'] ?? null) ? $parameters['code'] : ''));

        return $router;
    }

    /**
     * @return array{PaymentRequest, Payment}
     */
    private static function payment(): array
    {
        $customer = new Customer();
        $customer->setEmail('buyer@example.com');

        $address = new Address();
        $address->setFirstName('Jan');
        $address->setLastName('Kowalski');
        $address->setStreet('Długa 1');
        $address->setPostcode('00-001');
        $address->setCity('Warszawa');
        $address->setCountryCode('de');

        $order = new Order();
        $order->setNumber('000000001');
        $order->setLocaleCode('de_DE');
        $order->setCustomer($customer);
        $order->setBillingAddress($address);

        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setOrder($order);

        $method = new PaymentMethod();
        $method->setCode('P24');

        return [new PaymentRequest($payment, $method), $payment];
    }
}
