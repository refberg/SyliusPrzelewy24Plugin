<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Integration;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headless checkout through Sylius API Platform endpoints, without any shop UI.
 */
final class ApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_client_gets_the_przelewy24_redirect_url_and_checks_status(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('capture', $created['action']);
        self::assertSame('processing', $created['state']);
        $sessionId = $this->p24->lastSessionId();
        $transaction = $this->p24->transaction($sessionId);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/' . $transaction['token'], self::at($created, 'responseData', 'redirect_url'));
        self::assertSame($sessionId, self::at($created, 'responseData', 'session_id'));
        self::assertSame('https://app.example/payment/return', $transaction['urlReturn']);

        // The customer pays; the client asks for the status after the customer returns.
        $this->p24->pay($sessionId);
        $status = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'status',
        ]);

        self::assertSame('completed', $status['state']);
        self::assertSame('completed', self::at($status, 'responseData', 'payment_state'));
        self::assertSame(Transaction::STATUS_PAYMENT_MADE, self::at($status, 'responseData', 'p24_status'));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_invalid_continue_url_fails_the_request_without_calling_przelewy24(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'javascript:alert(1)'],
        ]);

        self::assertSame('failed', $created['state']);
        self::assertSame([], $this->p24->sessionIds());
        self::assertSame([], $this->p24->requests());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_action_is_not_available_to_shop_api_clients(): void
    {
        $payment = $this->createPlacedOrder();

        $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'refund',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->p24->requests('POST', '#/refund$#'));
    }
}
