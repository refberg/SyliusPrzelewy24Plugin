<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24ApiException;
use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24TransportException;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundRequest;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\TransactionRegistered;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function is_array;
use function is_int;
use function is_string;
use function mb_substr;
use function preg_match;
use function rawurlencode;
use function sprintf;

/**
 * Minimal Przelewy24 REST API v1 client (Basic authentication: POS ID and API key).
 *
 * Logs only HTTP status codes and identifiers. Request bodies, signatures and credentials
 * are never logged nor put into exception messages.
 */
final readonly class Przelewy24Client implements Przelewy24ClientInterface
{
    public const VERIFY_STATUS_SUCCESS = 'success';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9-]{1,100}$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SignatureVerifierInterface $signatureVerifier,
        private LoggerInterface $logger = new NullLogger(),
        private float $timeout = 15.0,
    ) {
    }

    public function registerTransaction(Przelewy24Credentials $credentials, array $transaction): TransactionRegistered
    {
        $sessionId = $transaction['sessionId'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $currency = $transaction['currency'] ?? null;
        if (!is_string($sessionId) || !is_int($amount) || !is_string($currency)) {
            throw new Przelewy24ApiException('Przelewy24 transaction needs sessionId, amount and currency.', 0);
        }

        $transaction['merchantId'] = $credentials->merchantId;
        $transaction['posId'] = $credentials->posId;
        $transaction['sign'] = $this->signatureVerifier->sign([
            'sessionId' => $sessionId,
            'merchantId' => $credentials->merchantId,
            'amount' => $amount,
            'currency' => $currency,
        ], $credentials->crcKey);

        [$statusCode, $data] = $this->request($credentials, 'POST', '/api/v1/transaction/register', $transaction);
        if (200 !== $statusCode) {
            throw $this->apiError('Registering Przelewy24 transaction failed', $statusCode, $data);
        }

        $result = $data['data'] ?? null;
        $token = is_array($result) ? Json::string($result, 'token') : null;
        if (null === $token || 1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            throw new Przelewy24ApiException('Przelewy24 registration response has no valid token.', $statusCode);
        }

        $this->logger->info('Przelewy24 transaction registered.', ['session_id' => $sessionId]);

        return new TransactionRegistered($token, $credentials->environment->baseUrl() . '/trnRequest/' . $token);
    }

    public function getTransaction(Przelewy24Credentials $credentials, string $sessionId): Transaction
    {
        [$statusCode, $data] = $this->request($credentials, 'GET', '/api/v1/transaction/by/sessionId/' . rawurlencode($sessionId));
        if (200 !== $statusCode) {
            throw $this->apiError('Reading Przelewy24 transaction failed', $statusCode, $data);
        }

        $result = $data['data'] ?? null;
        if (!is_array($result)) {
            throw new Przelewy24ApiException('Przelewy24 transaction response has no "data" object.', $statusCode);
        }

        $status = Json::int($result, 'status');
        $amount = Json::int($result, 'amount');
        $currency = Json::string($result, 'currency');
        if (Json::string($result, 'sessionId') !== $sessionId || null === $status || null === $amount || null === $currency) {
            throw new Przelewy24ApiException('Przelewy24 transaction response is incomplete.', $statusCode);
        }

        $orderId = Json::int($result, 'orderId');

        return new Transaction($sessionId, null !== $orderId && $orderId > 0 ? $orderId : null, $status, $amount, $currency);
    }

    public function verifyTransaction(Przelewy24Credentials $credentials, Transaction $transaction): void
    {
        if (null === $transaction->orderId) {
            throw new Przelewy24ApiException('Przelewy24 transaction without orderId cannot be verified.', 0);
        }

        [$statusCode, $data] = $this->request($credentials, 'PUT', '/api/v1/transaction/verify', [
            'merchantId' => $credentials->merchantId,
            'posId' => $credentials->posId,
            'sessionId' => $transaction->sessionId,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'orderId' => $transaction->orderId,
            'sign' => $this->signatureVerifier->sign([
                'sessionId' => $transaction->sessionId,
                'orderId' => $transaction->orderId,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ], $credentials->crcKey),
        ]);

        $result = $data['data'] ?? null;
        if (200 !== $statusCode || !is_array($result) || self::VERIFY_STATUS_SUCCESS !== Json::string($result, 'status')) {
            throw $this->apiError('Verifying Przelewy24 transaction failed', $statusCode, $data);
        }

        $this->logger->info('Przelewy24 transaction verified.', ['session_id' => $transaction->sessionId, 'p24_order_id' => $transaction->orderId]);
    }

    public function refund(Przelewy24Credentials $credentials, RefundRequest $refund): void
    {
        [$statusCode, $data] = $this->request($credentials, 'POST', '/api/v1/transaction/refund', [
            'requestId' => $refund->requestId,
            'refunds' => [[
                'orderId' => $refund->orderId,
                'sessionId' => $refund->sessionId,
                'amount' => $refund->amount,
                'description' => mb_substr($refund->description, 0, 35),
            ]],
            'refundsUuid' => $refund->refundsUuid,
            'urlStatus' => $refund->urlStatus,
        ]);

        $results = $data['data'] ?? null;
        $result = is_array($results) ? ($results[0] ?? null) : null;
        if (201 !== $statusCode || !is_array($result) || true !== ($result['status'] ?? null)) {
            throw $this->apiError('Requesting Przelewy24 refund failed', $statusCode, $data);
        }

        $this->logger->info('Przelewy24 refund requested.', ['p24_order_id' => $refund->orderId, 'refunds_uuid' => $refund->refundsUuid]);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<array-key, mixed>}
     */
    private function request(Przelewy24Credentials $credentials, string $method, string $path, ?array $json = null): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            'auth_basic' => [(string) $credentials->posId, $credentials->apiKey],
            'max_redirects' => 0,
            'timeout' => $this->timeout,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $credentials->environment->baseUrl() . $path, $options);
            $statusCode = $response->getStatusCode();

            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                $data = [];
            }
        } catch (TransportExceptionInterface) {
            $this->logger->warning('Przelewy24 API is unreachable.', ['method' => $method, 'path' => $path]);

            throw new Przelewy24TransportException('Could not reach Przelewy24 API.');
        } catch (HttpClientExceptionInterface) {
            throw new Przelewy24TransportException('Przelewy24 API returned an unreadable response.');
        }

        return [$statusCode, $data];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function apiError(string $message, int $statusCode, array $data): Przelewy24ApiException
    {
        $error = self::errorMessage($data);

        $this->logger->error($message . '.', ['http_status' => $statusCode, 'p24_error' => $error]);

        return new Przelewy24ApiException(
            sprintf('%s (HTTP %d%s).', $message, $statusCode, null !== $error ? ': ' . mb_substr($error, 0, 200) : ''),
            $statusCode,
        );
    }

    /**
     * Error responses carry "error" as a string, or (refunds) a list of per-refund results with a "message".
     *
     * @param array<array-key, mixed> $data
     */
    private static function errorMessage(array $data): ?string
    {
        foreach (['error', 'data'] as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $first = $value[0] ?? null;
                $message = is_array($first) ? Json::string($first, 'message') : null;
            } else {
                $message = Json::string($data, $key);
            }

            if (null !== $message) {
                return $message;
            }
        }

        return null;
    }
}
