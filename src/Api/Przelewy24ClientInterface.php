<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Api;

use Refberg\SyliusPrzelewy24Plugin\Api\Exception\Przelewy24Exception;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\RefundRequest;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\TransactionRegistered;
use Refberg\SyliusPrzelewy24Plugin\Config\Przelewy24Credentials;

interface Przelewy24ClientInterface
{
    /**
     * Registers a transaction (POST /api/v1/transaction/register). merchantId, posId and sign are added by the client.
     *
     * @param array<string, mixed> $transaction body with at least sessionId, amount and currency
     *
     * @throws Przelewy24Exception
     */
    public function registerTransaction(Przelewy24Credentials $credentials, array $transaction): TransactionRegistered;

    /**
     * @throws Przelewy24Exception
     */
    public function getTransaction(Przelewy24Credentials $credentials, string $sessionId): Transaction;

    /**
     * Confirms a paid transaction (PUT /api/v1/transaction/verify). Without it Przelewy24 does not settle the payment.
     *
     * @throws Przelewy24Exception
     */
    public function verifyTransaction(Przelewy24Credentials $credentials, Transaction $transaction): void;

    /**
     * @throws Przelewy24Exception
     */
    public function refund(Przelewy24Credentials $credentials, RefundRequest $refund): void;
}
