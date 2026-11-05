<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Support;

use LogicException;
use OutOfBoundsException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function base64_encode;
use function bin2hex;
use function explode;
use function hash;
use function in_array;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function random_bytes;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * In-memory imitation of the Przelewy24 sandbox REST API, used as MockHttpClient callback.
 *
 * Any request to a host other than the Przelewy24 sandbox throws, so tests can never reach production.
 * Requests are authenticated (Basic) and signed ("sign") like the real API expects.
 */
final class FakePrzelewy24
{
    public const HOST = 'sandbox.przelewy24.pl';

    public const MERCHANT_ID = 123456;

    public const POS_ID = 123456;

    public const CRC_KEY = 'test-crc-key';

    public const API_KEY = 'test-api-key';

    /** Above the signed 32-bit range, as the Przelewy24 documentation warns. */
    private const FIRST_ORDER_ID = 3000000000;

    /** @var array<string, array<string, int|string|null>> */
    private array $transactions = [];

    /** @var list<array<string, int|string>> */
    private array $refunds = [];

    /** @var list<array{method: string, path: string, body: string, headers: array<string, string>}> */
    private array $requests = [];

    /** @var list<array{int, array<string, mixed>, string|null}> */
    private array $queuedFailures = [];

    private int $counter = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (self::HOST !== $host) {
            throw new LogicException(sprintf('Tests must not call "%s". Only the Przelewy24 sandbox fake is available.', $host));
        }

        $path = $parts['path'] ?? '/';
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (is_string($header) && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];

        if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode(self::POS_ID . ':' . self::API_KEY)) {
            return self::json(401, ['code' => 401, 'error' => 'Incorrect authentication']);
        }

        foreach ($this->queuedFailures as $index => [$status, $data, $pathPattern]) {
            if (null === $pathPattern || 1 === preg_match($pathPattern, $path)) {
                unset($this->queuedFailures[$index]);
                $this->queuedFailures = array_values($this->queuedFailures);

                return self::json($status, $data);
            }
        }

        $data = '' !== $body ? json_decode($body, true, 16, JSON_THROW_ON_ERROR) : [];
        $data = is_array($data) ? $data : [];

        return match (true) {
            'POST' === $method && '/api/v1/transaction/register' === $path => $this->register($data),
            'PUT' === $method && '/api/v1/transaction/verify' === $path => $this->verify($data),
            'POST' === $method && '/api/v1/transaction/refund' === $path => $this->refund($data),
            'GET' === $method && 1 === preg_match('#^/api/v1/transaction/by/sessionId/(.+)$#', $path, $matches) => $this->bySessionId(rawurldecode($matches[1])),
            default => self::json(404, ['error' => 'Not found', 'code' => 404]),
        };
    }

    /**
     * Simulates the customer paying on the Przelewy24 page: the transaction gets an orderId
     * and waits for the merchant's verification (advance payment).
     */
    public function pay(string $sessionId): int
    {
        $this->transaction($sessionId);
        $orderId = self::FIRST_ORDER_ID + ++$this->counter;
        $this->transactions[$sessionId]['orderId'] = $orderId;
        $this->transactions[$sessionId]['status'] = 1;

        return $orderId;
    }

    public function setStatus(string $sessionId, int $status): void
    {
        $this->transaction($sessionId);
        $this->transactions[$sessionId]['status'] = $status;
    }

    /**
     * Changes what Przelewy24 reports for the transaction, to simulate tampering or a mismatch.
     */
    public function overrideTransaction(string $sessionId, string $field, int|string $value): void
    {
        $this->transaction($sessionId);
        $this->transactions[$sessionId][$field] = $value;
    }

    /**
     * The next request (to a path matching $pathPattern, when given) fails with this HTTP status.
     */
    public function failNextRequest(int $httpStatus, string $error = 'Undefined error', ?string $pathPattern = null): void
    {
        $this->queuedFailures[] = [$httpStatus, ['code' => $httpStatus, 'error' => $error], $pathPattern];
    }

    /**
     * Simulates a refund made by the merchant in the Przelewy24 panel, outside Sylius.
     *
     * @return string refundsUuid sent in the refund notification
     */
    public function createPanelRefund(string $sessionId, int $amount): string
    {
        $transaction = $this->transaction($sessionId);
        $refundsUuid = bin2hex(random_bytes(16));
        $this->refunds[] = [
            'requestId' => 'panel-' . $refundsUuid,
            'refundsUuid' => $refundsUuid,
            'orderId' => (int) $transaction['orderId'],
            'sessionId' => $sessionId,
            'amount' => $amount,
            'status' => 'completed',
        ];

        return $refundsUuid;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function transaction(string $sessionId): array
    {
        return $this->transactions[$sessionId] ?? throw new OutOfBoundsException(sprintf('Unknown fake Przelewy24 transaction "%s".', $sessionId));
    }

    public function lastSessionId(): string
    {
        $last = array_key_last($this->transactions);

        return null !== $last ? (string) $last : throw new LogicException('No Przelewy24 transaction has been registered.');
    }

    /**
     * @return list<string>
     */
    public function sessionIds(): array
    {
        return array_map(strval(...), array_keys($this->transactions));
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    /**
     * @return list<array{method: string, path: string, body: string, headers: array<string, string>}>
     */
    public function requests(?string $method = null, ?string $pathPattern = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => (null === $method || $request['method'] === $method) &&
                (null === $pathPattern || 1 === preg_match($pathPattern, $request['path'])),
        ));
    }

    /**
     * Builds a signed transaction notification as Przelewy24 sends it to urlStatus.
     *
     * @param array<string, int|string> $overrides
     */
    public function transactionNotificationBody(string $sessionId, array $overrides = [], string $crcKey = self::CRC_KEY): string
    {
        $transaction = $this->transaction($sessionId);
        $fields = array_merge([
            'merchantId' => self::MERCHANT_ID,
            'posId' => self::POS_ID,
            'sessionId' => $sessionId,
            'amount' => (int) $transaction['amount'],
            'originAmount' => (int) $transaction['amount'],
            'currency' => (string) $transaction['currency'],
            'orderId' => (int) $transaction['orderId'],
            'methodId' => 25,
            'statement' => 'p24-' . $sessionId,
        ], $overrides);

        $fields['sign'] = self::sign($fields, $crcKey);

        return json_encode($fields, JSON_THROW_ON_ERROR);
    }

    /**
     * Builds a signed refund notification as Przelewy24 sends it to the refund urlStatus.
     *
     * @param int $status 0 = completed, 1 = rejected
     */
    public function refundNotificationBody(string $refundsUuid, int $status, ?int $amount = null, string $crcKey = self::CRC_KEY): string
    {
        foreach ($this->refunds as $refund) {
            if ($refund['refundsUuid'] !== $refundsUuid) {
                continue;
            }

            $transaction = $this->transaction((string) $refund['sessionId']);
            $signed = [
                'orderId' => (int) $refund['orderId'],
                'sessionId' => (string) $refund['sessionId'],
                'refundsUuid' => $refundsUuid,
                'merchantId' => self::MERCHANT_ID,
                'amount' => $amount ?? (int) $refund['amount'],
                'currency' => (string) $transaction['currency'],
                'status' => $status,
            ];

            return json_encode($signed + [
                'requestId' => (string) $refund['requestId'],
                'timestamp' => 1767225600,
                'sign' => self::sign($signed, $crcKey),
            ], JSON_THROW_ON_ERROR);
        }

        throw new OutOfBoundsException('Unknown fake Przelewy24 refund.');
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function sign(array $fields, string $crcKey = self::CRC_KEY): string
    {
        unset($fields['sign']);
        $fields['crc'] = $crcKey;

        return hash('sha384', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function register(array $data): MockResponse
    {
        foreach (['merchantId', 'posId', 'sessionId', 'amount', 'currency', 'description', 'email', 'country', 'language', 'urlReturn', 'sign'] as $field) {
            if (!isset($data[$field])) {
                return self::json(400, ['code' => 400, 'error' => 'Invalid input data: ' . $field]);
            }
        }

        if (self::MERCHANT_ID !== $data['merchantId'] || self::POS_ID !== $data['posId']) {
            return self::json(400, ['code' => 400, 'error' => 'Invalid merchantId or posId']);
        }

        $sign = self::sign([
            'sessionId' => $data['sessionId'],
            'merchantId' => $data['merchantId'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
        ]);
        if ($sign !== $data['sign']) {
            return self::json(400, ['code' => 400, 'error' => 'Incorrect CRC value']);
        }

        $sessionId = self::str($data['sessionId']);
        if (isset($this->transactions[$sessionId])) {
            return self::json(400, ['code' => 400, 'error' => 'Transaction with this sessionId already exists']);
        }

        $token = sprintf('FAKE-TOKEN-%06d', ++$this->counter);
        $this->transactions[$sessionId] = [
            'sessionId' => $sessionId,
            'token' => $token,
            'orderId' => null,
            'status' => 0,
            'amount' => is_int($data['amount']) ? $data['amount'] : 0,
            'currency' => self::str($data['currency']),
            'description' => self::str($data['description']),
            'email' => self::str($data['email']),
            'country' => self::str($data['country']),
            'language' => self::str($data['language']),
            'urlReturn' => self::str($data['urlReturn']),
            'urlStatus' => self::str($data['urlStatus'] ?? ''),
        ];

        return self::json(200, ['data' => ['token' => $token], 'responseCode' => 0]);
    }

    private function bySessionId(string $sessionId): MockResponse
    {
        if (!isset($this->transactions[$sessionId])) {
            return self::json(404, ['error' => 'Transaction not found', 'responseCode' => 0]);
        }

        $transaction = $this->transactions[$sessionId];

        return self::json(200, ['data' => [
            'statement' => 'p24-' . $sessionId,
            'orderId' => $transaction['orderId'] ?? 0,
            'sessionId' => $sessionId,
            'status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency'],
            'date' => '202601011200',
            'dateOfTransaction' => '202601011201',
            'clientEmail' => $transaction['email'],
            'accountMD5' => '',
            'paymentMethod' => 25,
            'description' => $transaction['description'],
            'clientName' => '',
            'clientAddress' => '',
            'clientCity' => '',
            'clientPostcode' => '',
            'batchId' => 0,
            'fee' => '0',
        ], 'responseCode' => 0]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function verify(array $data): MockResponse
    {
        $sessionId = self::str($data['sessionId'] ?? '');
        $transaction = $this->transactions[$sessionId] ?? null;
        $sign = self::sign([
            'sessionId' => $data['sessionId'] ?? null,
            'orderId' => $data['orderId'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
        ]);

        if (
            null === $transaction ||
            $sign !== ($data['sign'] ?? null) ||
            self::MERCHANT_ID !== ($data['merchantId'] ?? null) ||
            self::POS_ID !== ($data['posId'] ?? null) ||
            $transaction['orderId'] !== ($data['orderId'] ?? null) ||
            $transaction['amount'] !== ($data['amount'] ?? null) ||
            $transaction['currency'] !== ($data['currency'] ?? null) ||
            !in_array($transaction['status'], [1, 2], true)
        ) {
            return self::json(400, ['code' => 400, 'error' => 'Invalid input data']);
        }

        $this->transactions[$sessionId]['status'] = 2;

        return self::json(200, ['data' => ['status' => 'success'], 'responseCode' => 0]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function refund(array $data): MockResponse
    {
        $requestId = self::str($data['requestId'] ?? '');
        $refundsUuid = self::str($data['refundsUuid'] ?? '');
        $items = $data['refunds'] ?? null;
        $item = is_array($items) ? ($items[0] ?? null) : null;
        if ('' === $requestId || '' === $refundsUuid || !is_array($item)) {
            return self::json(400, ['code' => 400, 'error' => 'Invalid input parameters']);
        }

        foreach ($this->refunds as $existing) {
            if ($existing['requestId'] === $requestId || $existing['refundsUuid'] === $refundsUuid) {
                return self::json(400, ['code' => 400, 'error' => 'Request already exists']);
            }
        }

        $sessionId = self::str($item['sessionId'] ?? '');
        $transaction = $this->transactions[$sessionId] ?? null;
        $amount = is_int($item['amount'] ?? null) ? $item['amount'] : 0;
        $result = ['orderId' => $item['orderId'] ?? null, 'sessionId' => $sessionId, 'amount' => $amount, 'description' => $item['description'] ?? ''];

        if (null === $transaction || $transaction['orderId'] !== ($item['orderId'] ?? null) || 2 !== $transaction['status']) {
            return self::json(409, ['code' => 409, 'error' => [$result + ['status' => false, 'message' => 'Transaction in wrong status - cannot refund']]]);
        }

        if ($amount <= 0 || $amount > (int) $transaction['amount'] - $this->refundedAmount($sessionId)) {
            return self::json(409, ['code' => 409, 'error' => [$result + ['status' => false, 'message' => 'The amount of refund exceeds available amount for the transaction']]]);
        }

        $this->refunds[] = [
            'requestId' => $requestId,
            'refundsUuid' => $refundsUuid,
            'orderId' => (int) $transaction['orderId'],
            'sessionId' => $sessionId,
            'amount' => $amount,
            'status' => 'pending',
            'urlStatus' => self::str($data['urlStatus'] ?? ''),
            'description' => self::str($item['description'] ?? ''),
        ];

        return self::json(201, ['data' => [$result + ['status' => true, 'message' => 'success']], 'responseCode' => 0]);
    }

    private function refundedAmount(string $sessionId): int
    {
        $total = 0;
        foreach ($this->refunds as $refund) {
            if ($refund['sessionId'] === $sessionId && 'rejected' !== $refund['status']) {
                $total += (int) $refund['amount'];
            }
        }

        return $total;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(int $status, array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
