<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidatorInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Reads one transaction from the Przelewy24 API, checks it against the payment, verifies it when it is paid
 * and may complete the payment, and applies its state.
 *
 * Przelewy24 settles a payment only after transaction/verify. A paid transaction that cannot complete
 * the payment (the payment is already paid, cancelled or failed) is therefore not verified.
 */
final readonly class PaymentSynchronizer implements PaymentSynchronizerInterface
{
    public function __construct(
        private Przelewy24ClientInterface $client,
        private TransactionValidatorInterface $validator,
        private PaymentStateApplierInterface $stateApplier,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function synchronize(PaymentInterface $payment, string $sessionId, Przelewy24Credentials $credentials, ?int $notifiedOrderId = null): string
    {
        $transaction = $this->client->getTransaction($credentials, $sessionId);

        $errors = $this->validator->validate($transaction, $payment, $notifiedOrderId);
        if ([] !== $errors) {
            $this->logger->error('Przelewy24 transaction does not match the Sylius payment; state not changed.', [
                'payment_id' => $payment->getId(),
                'session_id' => $sessionId,
                'errors' => $errors,
            ]);

            return self::RESULT_INVALID;
        }

        if (Transaction::STATUS_ADVANCE_PAYMENT === $transaction->status && $this->stateApplier->canComplete($payment, $transaction)) {
            $this->client->verifyTransaction($credentials, $transaction);
            $transaction = $transaction->withStatus(Transaction::STATUS_PAYMENT_MADE);
        }

        return $this->stateApplier->apply($payment, $transaction);
    }
}
