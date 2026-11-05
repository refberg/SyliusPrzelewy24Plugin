<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24TransportException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundRequest;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24Client;
use Refberg\SyliusPrzelewy24Plugin\Config\Environment;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\MemoryLogger;
use function json_decode;
use function json_encode;
use function str_repeat;
use const JSON_THROW_ON_ERROR;

final class Przelewy24ClientTest extends TestCase
{
    private FakePrzelewy24 $p24;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->p24 = new FakePrzelewy24();
        $this->logger = new MemoryLogger();
    }

    public function test_register_adds_merchant_pos_and_sign_and_returns_the_payment_page(): void
    {
        $registered = $this->client()->registerTransaction(self::credentials(), self::transaction());

        $sessionId = 'order-1-abcdef012345';
        $token = (string) $this->p24->transaction($sessionId)['token'];
        self::assertSame($token, $registered->token);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/' . $token, $registered->redirectUrl);

        $request = $this->p24->requests('POST')[0];
        $body = json_decode($request['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(FakePrzelewy24::MERCHANT_ID, $body['merchantId']);
        self::assertSame(FakePrzelewy24::POS_ID, $body['posId']);
        self::assertSame(FakePrzelewy24::sign(['sessionId' => $sessionId, 'merchantId' => FakePrzelewy24::MERCHANT_ID, 'amount' => 1500, 'currency' => 'PLN']), $body['sign']);
        self::assertSame('application/json', $request['headers']['accept']);
    }

    public function test_production_uses_the_production_host(): void
    {
        $urls = [];
        $client = new Przelewy24Client(new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse((string) json_encode(['data' => ['token' => 'TOKEN-1']]), ['http_code' => 200]);
        }), new SignatureVerifier());

        $registered = $client->registerTransaction(self::credentials(Environment::Production), self::transaction());

        self::assertSame(['https://secure.przelewy24.pl/api/v1/transaction/register'], $urls);
        self::assertSame('https://secure.przelewy24.pl/trnRequest/TOKEN-1', $registered->redirectUrl);
    }

    public function test_register_rejects_a_token_that_could_change_the_payment_url(): void
    {
        $client = new Przelewy24Client(new MockHttpClient(new MockResponse((string) json_encode(['data' => ['token' => '../evil?x=1']]), ['http_code' => 200])), new SignatureVerifier());

        $this->expectException(Przelewy24ApiException::class);
        $this->expectExceptionMessage('no valid token');

        $client->registerTransaction(self::credentials(), self::transaction());
    }

    public function test_register_without_session_amount_or_currency_does_not_call_przelewy24(): void
    {
        try {
            $this->client()->registerTransaction(self::credentials(), ['sessionId' => 'x', 'amount' => '1500', 'currency' => 'PLN']);
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException) {
        }

        self::assertSame([], $this->p24->requests());
    }

    public function test_get_transaction_reads_status_amount_and_order_id(): void
    {
        $client = $this->client();
        $client->registerTransaction(self::credentials(), self::transaction());

        $unpaid = $client->getTransaction(self::credentials(), 'order-1-abcdef012345');
        self::assertSame(Transaction::STATUS_NO_PAYMENT, $unpaid->status);
        self::assertNull($unpaid->orderId);
        self::assertFalse($unpaid->isPaid());

        $orderId = $this->p24->pay('order-1-abcdef012345');
        $paid = $client->getTransaction(self::credentials(), 'order-1-abcdef012345');
        self::assertSame(Transaction::STATUS_ADVANCE_PAYMENT, $paid->status);
        self::assertSame($orderId, $paid->orderId);
        self::assertSame(1500, $paid->amount);
        self::assertSame('PLN', $paid->currency);
        self::assertTrue($paid->isPaid());
    }

    public function test_unknown_transaction_is_reported_as_not_found(): void
    {
        try {
            $this->client()->getTransaction(self::credentials(), 'missing');
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertTrue($exception->isNotFound());
            self::assertFalse($exception->isTransient());
            self::assertSame('Reading Przelewy24 transaction failed (HTTP 404: Transaction not found).', $exception->getMessage());
        }
    }

    public function test_transaction_of_another_session_is_rejected(): void
    {
        $client = new Przelewy24Client(new MockHttpClient(new MockResponse((string) json_encode(['data' => [
            'sessionId' => 'other', 'status' => 2, 'amount' => 1500, 'currency' => 'PLN', 'orderId' => 1,
        ]]), ['http_code' => 200])), new SignatureVerifier());

        $this->expectException(Przelewy24ApiException::class);
        $this->expectExceptionMessage('incomplete');

        $client->getTransaction(self::credentials(), 'order-1-abcdef012345');
    }

    public function test_verify_sends_the_documented_sign(): void
    {
        $client = $this->client();
        $client->registerTransaction(self::credentials(), self::transaction());
        $orderId = $this->p24->pay('order-1-abcdef012345');

        $client->verifyTransaction(self::credentials(), $client->getTransaction(self::credentials(), 'order-1-abcdef012345'));

        self::assertSame(Transaction::STATUS_PAYMENT_MADE, $this->p24->transaction('order-1-abcdef012345')['status']);
        $body = json_decode($this->p24->requests('PUT')[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(FakePrzelewy24::sign(['sessionId' => 'order-1-abcdef012345', 'orderId' => $orderId, 'amount' => 1500, 'currency' => 'PLN']), $body['sign']);
    }

    public function test_verify_without_order_id_does_not_call_przelewy24(): void
    {
        try {
            $this->client()->verifyTransaction(self::credentials(), new Transaction('order-1-abcdef012345', null, Transaction::STATUS_ADVANCE_PAYMENT, 1500, 'PLN'));
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException) {
        }

        self::assertSame([], $this->p24->requests());
    }

    public function test_rejected_verification_fails(): void
    {
        $client = $this->client();
        $client->registerTransaction(self::credentials(), self::transaction());
        $orderId = $this->p24->pay('order-1-abcdef012345');

        $this->expectException(Przelewy24ApiException::class);
        $this->expectExceptionMessage('Verifying Przelewy24 transaction failed (HTTP 400: Invalid input data).');

        $client->verifyTransaction(self::credentials(), new Transaction('order-1-abcdef012345', $orderId, Transaction::STATUS_ADVANCE_PAYMENT, 1, 'PLN'));
    }

    public function test_refund_reports_the_per_refund_error_message(): void
    {
        $client = $this->client();
        $client->registerTransaction(self::credentials(), self::transaction());
        $orderId = $this->p24->pay('order-1-abcdef012345');
        $this->p24->setStatus('order-1-abcdef012345', Transaction::STATUS_PAYMENT_MADE);

        $client->refund(self::credentials(), self::refund($orderId, 1000, 'request-1'));
        self::assertCount(1, $this->p24->refunds());

        try {
            $client->refund(self::credentials(), self::refund($orderId, 1000, 'request-2'));
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertSame(409, $exception->getHttpStatusCode());
            self::assertStringContainsString('The amount of refund exceeds available amount for the transaction', $exception->getMessage());
        }
    }

    public function test_refund_description_is_cut_to_35_characters(): void
    {
        $client = $this->client();
        $client->registerTransaction(self::credentials(), self::transaction());
        $orderId = $this->p24->pay('order-1-abcdef012345');
        $this->p24->setStatus('order-1-abcdef012345', Transaction::STATUS_PAYMENT_MADE);

        $client->refund(self::credentials(), new RefundRequest('request-1', 'uuid-1', $orderId, 'order-1-abcdef012345', 1500, str_repeat('ż', 40), 'https://shop.example/payment-methods/P24'));

        self::assertSame(str_repeat('ż', 35), $this->p24->refunds()[0]['description'] ?? null);
    }

    public function test_rejected_credentials_are_an_authentication_failure_without_secrets_in_the_message(): void
    {
        $credentials = new Przelewy24Credentials(FakePrzelewy24::MERCHANT_ID, FakePrzelewy24::POS_ID, FakePrzelewy24::CRC_KEY, 'wrong-api-key', Environment::Sandbox);

        try {
            $this->client()->getTransaction($credentials, 'order-1-abcdef012345');
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertTrue($exception->isAuthenticationFailure());
            self::assertStringNotContainsString('wrong-api-key', $exception->getMessage());
            self::assertStringNotContainsString(FakePrzelewy24::CRC_KEY, $exception->getMessage());
        }

        foreach ($this->logger->records as $record) {
            self::assertStringNotContainsString('wrong-api-key', (string) json_encode($record));
        }
    }

    public function test_server_errors_and_throttling_are_transient(): void
    {
        foreach ([500 => true, 503 => true, 408 => true, 429 => true, 400 => false, 409 => false] as $status => $transient) {
            self::assertSame($transient, (new Przelewy24ApiException('x', $status))->isTransient(), (string) $status);
        }
    }

    public function test_network_failure_is_a_transport_exception(): void
    {
        $client = new Przelewy24Client(new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout')), new SignatureVerifier(), $this->logger);

        $this->expectException(Przelewy24TransportException::class);

        $client->getTransaction(self::credentials(), 'order-1-abcdef012345');
    }

    public function test_redirects_are_never_followed(): void
    {
        $client = new Przelewy24Client(new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.example/']])), new SignatureVerifier());

        try {
            $client->getTransaction(self::credentials(), 'order-1-abcdef012345');
            self::fail('Expected an exception.');
        } catch (Przelewy24ApiException $exception) {
            self::assertSame(302, $exception->getHttpStatusCode());
        }
    }

    private function client(): Przelewy24Client
    {
        return new Przelewy24Client(new MockHttpClient($this->p24, 'https://' . FakePrzelewy24::HOST), new SignatureVerifier(), $this->logger);
    }

    private static function credentials(Environment $environment = Environment::Sandbox): Przelewy24Credentials
    {
        return new Przelewy24Credentials(FakePrzelewy24::MERCHANT_ID, FakePrzelewy24::POS_ID, FakePrzelewy24::CRC_KEY, FakePrzelewy24::API_KEY, $environment);
    }

    /**
     * @return array<string, mixed>
     */
    private static function transaction(): array
    {
        return [
            'sessionId' => 'order-1-abcdef012345',
            'amount' => 1500,
            'currency' => 'PLN',
            'description' => 'Order 1',
            'email' => 'buyer@example.com',
            'country' => 'PL',
            'language' => 'pl',
            'urlReturn' => 'https://shop.example/return',
            'urlStatus' => 'https://shop.example/payment-methods/P24',
        ];
    }

    private static function refund(int $orderId, int $amount, string $requestId): RefundRequest
    {
        return new RefundRequest($requestId, 'uuid-' . $requestId, $orderId, 'order-1-abcdef012345', $amount, 'Refund 1', 'https://shop.example/payment-methods/P24');
    }
}
