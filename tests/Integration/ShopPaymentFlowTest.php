<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\FakePrzelewy24;
use function json_decode;
use function json_encode;
use function str_replace;
use const JSON_THROW_ON_ERROR;

final class ShopPaymentFlowTest extends IntegrationTestCase
{
    public function test_customer_is_redirected_to_przelewy24_with_server_side_amount(): void
    {
        $payment = $this->createPlacedOrder();

        $redirect = $this->payInShop();

        $sessionId = $this->p24->lastSessionId();
        $transaction = $this->p24->transaction($sessionId);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/' . $transaction['token'], $redirect);
        self::assertSame(1500, $transaction['amount']);
        self::assertSame('PLN', $transaction['currency']);
        self::assertSame('buyer@example.com', $transaction['email']);
        self::assertSame('en', $transaction['language']);
        self::assertSame('PL', $transaction['country']);
        self::assertSame('http://localhost/payment-methods/P24', $transaction['urlStatus']);
        self::assertMatchesRegularExpression('#^http://localhost/en_US/order/after-pay/[0-9a-f-]{36}$#', (string) $transaction['urlReturn']);
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);
        self::assertMatchesRegularExpression('#^000000001-' . $paymentId . '-[0-9a-f]{12}$#', $sessionId);

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame($sessionId, $this->details($payment)->getCurrentSessionId());
    }

    public function test_opening_the_pay_page_again_reuses_the_transaction(): void
    {
        $this->createPlacedOrder();

        $first = $this->payInShop();
        $second = $this->payInShop();

        self::assertSame($first, $second);
        self::assertCount(1, $this->p24->sessionIds());
    }

    public function test_returning_from_przelewy24_does_not_pay_the_order_by_itself(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();

        // The return URL can be opened by anyone: it is not proof of payment.
        self::assertTrue($this->returnFromPrzelewy24($sessionId)->isRedirect());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->p24->requests('GET', '#^/api/v1/transaction/by/sessionId/#'));
        self::assertSame([], $this->p24->requests('PUT'));
    }

    public function test_returning_after_the_customer_paid_verifies_the_transaction_and_marks_it_paid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);

        self::assertStringContainsString('/order/thank-you', (string) $this->returnFromPrzelewy24($sessionId)->headers->get('Location'));

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        self::assertCount(1, $this->p24->requests('PUT', '#^/api/v1/transaction/verify$#'));
        self::assertSame(Transaction::STATUS_PAYMENT_MADE, $this->p24->transaction($sessionId)['status']);
    }

    public function test_signed_notification_verifies_the_transaction_and_pays_the_order(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $orderId = $this->p24->pay($sessionId);

        $response = $this->notifyTransaction($sessionId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        $details = PaymentDetails::of($payment);
        self::assertSame($sessionId, $details->getCompletedSessionId());
        self::assertSame($orderId, $details->getOrderId($sessionId));
        self::assertSame(Transaction::STATUS_PAYMENT_MADE, $details->getTransactionStatus($sessionId));

        $verify = $this->p24->requests('PUT', '#^/api/v1/transaction/verify$#');
        self::assertCount(1, $verify);
        $body = json_decode($verify[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame([
            'merchantId' => FakePrzelewy24::MERCHANT_ID,
            'posId' => FakePrzelewy24::POS_ID,
            'sessionId' => $sessionId,
            'amount' => 1500,
            'currency' => 'PLN',
            'orderId' => $orderId,
            'sign' => FakePrzelewy24::sign(['sessionId' => $sessionId, 'orderId' => $orderId, 'amount' => 1500, 'currency' => 'PLN']),
        ], $body);
    }

    public function test_duplicated_notifications_are_accepted_and_change_nothing(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
        self::assertCount(1, $this->p24->requests('PUT'));
        self::assertCount(3, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    public function test_notification_with_wrong_sign_is_rejected_and_not_stored(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $body = $this->p24->transactionNotificationBody($sessionId);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->p24->transactionNotificationBody($sessionId, crcKey: 'attacker-crc'))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify(str_replace('"sign"', '"unsigned"', $body))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify(str_replace('"amount":1500', '"amount":1', $body))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify('not json')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
        self::assertSame([], $this->p24->requests('GET', '#^/api/v1/transaction/by/sessionId/#'));
        self::assertSame([], $this->p24->requests('PUT'));
    }

    public function test_signed_notification_of_another_merchant_or_shop_is_rejected(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->p24->transactionNotificationBody($sessionId, ['merchantId' => 999999]))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->p24->transactionNotificationBody($sessionId, ['posId' => 999999]))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_for_an_unknown_transaction_is_rejected(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);

        // Correct format and payment ID, but this transaction was never registered for the payment.
        $foreign = $this->p24->transactionNotificationBody($sessionId, ['sessionId' => '000000001-' . $paymentId . '-abcdef012345']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($foreign)->getStatusCode());

        $noPayment = $this->p24->transactionNotificationBody($sessionId, ['sessionId' => 'other-order']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($noPayment)->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_for_a_method_without_credentials_returns_503_so_przelewy24_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $this->createPrzelewy24Method('P24_EMPTY')->getGatewayConfig()?->setConfig([Przelewy24Gateway::CONFIG_ENVIRONMENT => 'sandbox']);
        $this->em()->flush();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->notify($this->p24->transactionNotificationBody($sessionId), 'P24_EMPTY')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    /**
     * @return iterable<string, array{string, int|string}>
     */
    public static function mismatches(): iterable
    {
        yield 'amount' => ['amount', 1];
        yield 'currency' => ['currency', 'EUR'];
        yield 'order id' => ['orderId', 42];
    }

    #[DataProvider('mismatches')]
    public function test_transaction_that_does_not_match_the_payment_is_never_verified_nor_paid(string $field, int|string $value): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $body = $this->p24->transactionNotificationBody($sessionId);

        $this->p24->overrideTransaction($sessionId, $field, $value);

        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->p24->requests('PUT'));
    }

    public function test_notification_is_not_trusted_without_the_przelewy24_api(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $body = $this->p24->transactionNotificationBody($sessionId);

        // Correctly signed, but the Przelewy24 API reports the transaction as unpaid.
        $this->p24->setStatus($sessionId, Transaction::STATUS_NO_PAYMENT);
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->p24->requests('PUT'));
    }

    public function test_late_notifications_do_not_undo_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->payAndNotify();

        $this->p24->setStatus($sessionId, Transaction::STATUS_PAYMENT_RETURNED);
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(Transaction::STATUS_PAYMENT_RETURNED, PaymentDetails::of($payment)->getTransactionStatus($sessionId));
    }

    public function test_two_paid_transactions_never_pay_the_sylius_order_twice(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $firstSessionId = $this->p24->lastSessionId();

        // The first attempt is abandoned; the customer starts a new one.
        $this->finalizeCaptureRequests(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->payInShop();
        $secondSessionId = $this->p24->lastSessionId();
        self::assertNotSame($firstSessionId, $secondSessionId);

        $this->payAndNotify();
        $this->p24->pay($firstSessionId);
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($firstSessionId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame($secondSessionId, $details->getCompletedSessionId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'session_id' => $firstSessionId]], $details->warnings());

        // The second payment is not verified, so Przelewy24 does not settle it.
        self::assertCount(1, $this->p24->requests('PUT'));
        self::assertSame(Transaction::STATUS_ADVANCE_PAYMENT, $this->p24->transaction($firstSessionId)['status']);
    }

    public function test_transient_przelewy24_error_returns_5xx_so_przelewy24_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $this->p24->failNextRequest(503);

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_transient_error_of_the_verification_returns_5xx_and_keeps_the_payment_unpaid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);

        // Reading the transaction succeeds, the verification fails.
        $this->p24->failNextRequest(500, pathPattern: '#/transaction/verify$#');

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    #[TestWith([401])]
    #[TestWith([403])]
    public function test_przelewy24_rejecting_the_plugin_credentials_returns_5xx_so_przelewy24_retries(int $httpStatus): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->p24->pay($sessionId);
        $this->p24->failNextRequest($httpStatus, 'Incorrect authentication');

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_payment_made_after_the_customer_switched_payment_method_completes_it_with_a_warning(string $confirmedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $payment = $this->switchToBankTransfer($payment);

        $this->p24->pay($sessionId);
        if ('notification' === $confirmedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
            self::assertTrue($this->returnFromPrzelewy24($sessionId)->isRedirect());
        } else {
            self::assertTrue($this->returnFromPrzelewy24($sessionId)->isRedirect());
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        $details = PaymentDetails::of($payment);
        self::assertSame($sessionId, $details->getCompletedSessionId());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'session_id' => $sessionId]], $details->warnings());

        // The restored Przelewy24 method lets an admin refund reach Przelewy24.
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertCount(1, $this->p24->requests('POST', '#^/api/v1/transaction/refund$#'));
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_unpaid_transaction_after_the_customer_switched_payment_method_keeps_the_new_method(string $checkedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $payment = $this->switchToBankTransfer($payment);

        if ('notification' === $checkedBy) {
            $this->p24->pay($sessionId);
            $body = $this->p24->transactionNotificationBody($sessionId);
            $this->p24->setStatus($sessionId, Transaction::STATUS_NO_PAYMENT);
            self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        } else {
            self::assertTrue($this->returnFromPrzelewy24($sessionId)->isRedirect());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertSame('BANK_TRANSFER', $payment->getMethod()?->getCode());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    public function test_old_transaction_paid_after_switching_to_another_przelewy24_method_is_refunded_with_the_paid_method(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();

        $payment = $this->reloadPayment($payment);
        $payment->setMethod($this->createPrzelewy24Method('P24_OTHER', [Przelewy24Gateway::CONFIG_CRC_KEY => 'other-crc-key']));
        $this->em()->flush();

        $this->p24->pay($sessionId);
        self::assertTrue($this->returnFromPrzelewy24($sessionId)->isRedirect());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'session_id' => $sessionId]], PaymentDetails::of($payment)->warnings());

        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertSame(self::METHOD_CODE, $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND)[0]->getMethod()->getCode());
        self::assertCount(1, $this->p24->requests('POST', '#^/api/v1/transaction/refund$#'));
    }

    public function test_notification_signed_for_another_przelewy24_method_never_pays_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();
        $this->createPrzelewy24Method('P24_OTHER', [Przelewy24Gateway::CONFIG_CRC_KEY => 'other-crc-key']);
        $this->em()->flush();
        $this->p24->pay($sessionId);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->p24->transactionNotificationBody($sessionId), 'P24_OTHER')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->p24->requests('PUT'));
    }

    private function switchToBankTransfer(PaymentInterface $payment): PaymentInterface
    {
        $bankTransfer = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $bankTransfer->setCode('BANK_TRANSFER');
        $bankTransfer->setCurrentLocale('en_US');
        $bankTransfer->setFallbackLocale('en_US');
        $bankTransfer->setName('Bank transfer');
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($bankTransfer);
        $this->em()->persist($bankTransfer);
        $this->em()->flush();

        return $payment;
    }

    private function finalizeCaptureRequests(string $transition): void
    {
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        foreach ($this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE) as $request) {
            if ($stateMachine->can($request, PaymentRequestTransitions::GRAPH, $transition)) {
                $stateMachine->apply($request, PaymentRequestTransitions::GRAPH, $transition);
            }
        }
        $this->em()->flush();
    }
}
