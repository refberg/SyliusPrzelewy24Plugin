<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Notification;

use JsonException;
use Refberg\SyliusPrzelewy24Plugin\Api\Json;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface;
use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;

/**
 * Verifies the "sign" of a Przelewy24 notification and extracts its identifiers.
 *
 * A refund notification is recognized by its "refundsUuid" field; everything else is read as
 * a transaction notification. Both must belong to the merchant (and, for transactions, the shop)
 * of the payment method.
 */
final readonly class NotificationParser implements NotificationParserInterface
{
    public function __construct(private SignatureVerifierInterface $signatureVerifier)
    {
    }

    public function parse(string $rawBody, Przelewy24Credentials $credentials): ?Notification
    {
        if ('' === $rawBody) {
            return null;
        }

        try {
            $data = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return array_key_exists('refundsUuid', $data)
            ? $this->refund($data, $credentials)
            : $this->transaction($data, $credentials);
    }

    /**
     * Parses a notification stored in PaymentRequest::$payload by Sylius' NotifyPayloadProcessor.
     */
    public function parseStoredPayload(mixed $payload, Przelewy24Credentials $credentials): ?Notification
    {
        $httpRequest = is_array($payload) ? ($payload['http_request'] ?? null) : null;
        $content = is_array($httpRequest) ? ($httpRequest['content'] ?? null) : null;

        return is_string($content) ? $this->parse($content, $credentials) : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function transaction(array $data, Przelewy24Credentials $credentials): ?Notification
    {
        if (!$this->signatureVerifier->verifyTransactionNotification($data, $credentials->crcKey)) {
            return null;
        }

        if (Json::int($data, 'merchantId') !== $credentials->merchantId || Json::int($data, 'posId') !== $credentials->posId) {
            return null;
        }

        return self::notification(Notification::TYPE_TRANSACTION, $data);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function refund(array $data, Przelewy24Credentials $credentials): ?Notification
    {
        if (!$this->signatureVerifier->verifyRefundNotification($data, $credentials->crcKey)) {
            return null;
        }

        $status = Json::int($data, 'status');
        $refundStatus = null !== $status ? Refund::fromNotificationStatus($status) : null;
        $refundsUuid = Json::string($data, 'refundsUuid');
        if (Json::int($data, 'merchantId') !== $credentials->merchantId || null === $refundStatus || null === $refundsUuid) {
            return null;
        }

        return self::notification(Notification::TYPE_REFUND, $data, $refundsUuid, $refundStatus);
    }

    /**
     * @param Notification::TYPE_* $type
     * @param array<array-key, mixed> $data
     */
    private static function notification(string $type, array $data, ?string $refundsUuid = null, ?string $refundStatus = null): ?Notification
    {
        $sessionId = Json::string($data, 'sessionId');
        $orderId = Json::int($data, 'orderId');
        $amount = Json::int($data, 'amount');
        $currency = Json::string($data, 'currency');
        if (null === $sessionId || null === $orderId || null === $amount || null === $currency) {
            return null;
        }

        return new Notification($type, $sessionId, $orderId, $amount, $currency, $refundsUuid, $refundStatus);
    }
}
