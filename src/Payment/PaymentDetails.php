<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Payment;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Component\Payment\Model\PaymentInterface;
use function array_reverse;
use function is_array;
use function is_int;
use function is_string;

/**
 * Typed access to the Przelewy24 data stored in Payment::$details[Przelewy24Gateway::DETAILS_KEY].
 *
 * Shape:
 *   transactions:         map sessionId => {token, status, amount, currency, order_id, cancelled}
 *   current_session_id:   transaction registered most recently for this payment
 *   completed_session_id: transaction that completed this payment
 *   refunds:              map refundsUuid => {request_id, status, amount, currency, external}
 *   warnings:             list of {type, session_id}
 *
 * Only scalars and arrays are stored, so the data stays JSON-serializable.
 */
final class PaymentDetails
{
    public const WARNING_DUPLICATE_PAYMENT = 'duplicate_payment';

    public const WARNING_LATE_PAYMENT = 'payment_completed_after_failure';

    public const WARNING_PAYMENT_METHOD_CHANGED = 'paid_with_previous_payment_method';

    /** @var array<array-key, mixed> */
    private array $data;

    private function __construct(private readonly PaymentInterface $payment)
    {
        $data = $payment->getDetails()[Przelewy24Gateway::DETAILS_KEY] ?? [];
        $this->data = is_array($data) ? $data : [];
    }

    public static function of(PaymentInterface $payment): self
    {
        return new self($payment);
    }

    public function save(): void
    {
        $details = $this->payment->getDetails();
        $details[Przelewy24Gateway::DETAILS_KEY] = $this->data;
        $this->payment->setDetails($details);
    }

    public function addTransaction(string $sessionId, string $token, int $amount, string $currency): void
    {
        $transactions = $this->transactions();
        $transactions[$sessionId] = [
            'token' => $token,
            'status' => Transaction::STATUS_NO_PAYMENT,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['transactions'] = $transactions;
        $this->data['current_session_id'] = $sessionId;
    }

    public function hasTransaction(string $sessionId): bool
    {
        return isset($this->transactions()[$sessionId]);
    }

    public function getTransactionStatus(string $sessionId): ?int
    {
        $value = $this->transactions()[$sessionId]['status'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function getOrderId(string $sessionId): ?int
    {
        $value = $this->transactions()[$sessionId]['order_id'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Records the latest known status and the Przelewy24 orderId. A status is never moved back
     * (statuses only grow: no payment, advance payment, payment made, payment returned).
     */
    public function recordTransaction(Transaction $transaction): void
    {
        $transactions = $this->transactions();
        if (!isset($transactions[$transaction->sessionId])) {
            return;
        }

        $current = $transactions[$transaction->sessionId]['status'] ?? null;
        if (!is_int($current) || $transaction->status > $current) {
            $transactions[$transaction->sessionId]['status'] = $transaction->status;
        }

        if (null !== $transaction->orderId) {
            $transactions[$transaction->sessionId]['order_id'] = $transaction->orderId;
        }

        $this->data['transactions'] = $transactions;
    }

    /**
     * Marks the transaction as cancelled in Sylius. Przelewy24 has no API to cancel a registered transaction,
     * so a later payment of it is never verified.
     */
    public function cancelTransaction(string $sessionId): void
    {
        $transactions = $this->transactions();
        if (!isset($transactions[$sessionId])) {
            return;
        }

        $transactions[$sessionId]['cancelled'] = true;
        $this->data['transactions'] = $transactions;
    }

    public function isCancelled(string $sessionId): bool
    {
        return true === ($this->transactions()[$sessionId]['cancelled'] ?? false);
    }

    public function getCurrentSessionId(): ?string
    {
        $value = $this->data['current_session_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getCompletedSessionId(): ?string
    {
        $value = $this->data['completed_session_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function setCompletedSessionId(string $sessionId): void
    {
        $this->data['completed_session_id'] = $sessionId;
    }

    /**
     * @return list<string> session IDs of transactions that may still be paid, newest first
     */
    public function getOpenSessionIds(): array
    {
        $open = [];
        foreach ($this->transactions() as $sessionId => $transaction) {
            $status = $transaction['status'] ?? null;
            if (!Transaction::isFinalStatus(is_int($status) ? $status : null) && true !== ($transaction['cancelled'] ?? false)) {
                $open[] = (string) $sessionId;
            }
        }

        return array_reverse($open);
    }

    public function addWarning(string $type, string $sessionId): void
    {
        $warnings = $this->warnings();
        foreach ($warnings as $warning) {
            if (($warning['type'] ?? null) === $type && ($warning['session_id'] ?? null) === $sessionId) {
                return;
            }
        }

        $warnings[] = ['type' => $type, 'session_id' => $sessionId];
        $this->data['warnings'] = $warnings;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function warnings(): array
    {
        $warnings = $this->data['warnings'] ?? [];
        if (!is_array($warnings)) {
            return [];
        }

        $result = [];
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $result[] = $warning;
            }
        }

        return $result;
    }

    /**
     * Records a refund requested by Sylius and accepted by Przelewy24.
     */
    public function recordRefund(string $refundsUuid, string $requestId, int $amount, string $currency): void
    {
        $refunds = $this->refunds();
        $refunds[$refundsUuid] = [
            'request_id' => $requestId,
            'status' => Refund::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['refunds'] = $refunds;
    }

    public function hasRefund(string $refundsUuid): bool
    {
        return isset($this->refunds()[$refundsUuid]);
    }

    /**
     * A final status (completed, rejected) is never overwritten.
     *
     * @return bool false when the refund is unknown
     */
    public function updateRefundStatus(string $refundsUuid, string $status): bool
    {
        $refunds = $this->refunds();
        if (!isset($refunds[$refundsUuid])) {
            return false;
        }

        $current = $refunds[$refundsUuid]['status'] ?? null;
        if (!Refund::isFinalStatus(is_string($current) ? $current : null)) {
            $refunds[$refundsUuid]['status'] = $status;
            $this->data['refunds'] = $refunds;
        }

        return true;
    }

    /**
     * Records a refund made outside Sylius (e.g. in the Przelewy24 panel), keyed by its refundsUuid.
     *
     * @return bool false when the refund was already known
     */
    public function recordExternalRefund(string $refundsUuid, string $status, int $amount, string $currency): bool
    {
        if ($this->hasRefund($refundsUuid)) {
            return false;
        }

        $refunds = $this->refunds();
        $refunds[$refundsUuid] = [
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'external' => true,
        ];
        $this->data['refunds'] = $refunds;

        return true;
    }

    public function completedRefundAmount(): int
    {
        return $this->refundAmount(static fn (mixed $status): bool => Refund::STATUS_COMPLETED === $status);
    }

    /**
     * Refunds that are not rejected: completed ones and those still in progress.
     */
    public function activeRefundAmount(): int
    {
        return $this->refundAmount(static fn (mixed $status): bool => Refund::STATUS_REJECTED !== $status);
    }

    /**
     * Only refunds requested by Sylius count; refunds made outside Sylius are covered by activeRefundAmount().
     */
    public function hasActiveRefund(): bool
    {
        foreach ($this->refunds() as $refund) {
            if (true !== ($refund['external'] ?? false) && ($refund['status'] ?? null) !== Refund::STATUS_REJECTED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    public function refunds(): array
    {
        $refunds = $this->data['refunds'] ?? [];

        return is_array($refunds) ? self::mapOfArrays($refunds) : [];
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    private function transactions(): array
    {
        $transactions = $this->data['transactions'] ?? [];

        return is_array($transactions) ? self::mapOfArrays($transactions) : [];
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    private static function mapOfArrays(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param callable(mixed): bool $statusFilter
     */
    private function refundAmount(callable $statusFilter): int
    {
        $total = 0;
        foreach ($this->refunds() as $refund) {
            $amount = $refund['amount'] ?? 0;
            if (is_int($amount) && $statusFilter($refund['status'] ?? null)) {
                $total += $amount;
            }
        }

        return $total;
    }
}
