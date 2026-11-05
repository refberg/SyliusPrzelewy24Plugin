<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Sylius\Component\Payment\Model\PaymentInterface;

interface TransactionValidatorInterface
{
    /**
     * @param int|null $expectedOrderId Przelewy24 orderId from a signed notification, when there is one
     *
     * @return list<string> problems found, empty when the transaction matches the payment
     */
    public function validate(Transaction $transaction, PaymentInterface $payment, ?int $expectedOrderId = null): array;
}
