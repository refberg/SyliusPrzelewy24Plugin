<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Sandbox;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPrzelewy24Plugin\Integration\IntegrationTestCase;
use Tests\Refberg\SyliusPrzelewy24Plugin\Support\SandboxEnvironment;
use function fwrite;
use function sprintf;
use const STDERR;

/**
 * Manual end-to-end tests against the real Przelewy24 sandbox (P24_SANDBOX=1, see README).
 *
 * The payment itself is made by hand on the Przelewy24 page: the test prints the URL and checks what it can
 * without a paid transaction (registration, authentication, status check of an unpaid transaction).
 */
final class SandboxFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!SandboxEnvironment::isEnabled() || null === SandboxEnvironment::credentials()) {
            self::markTestSkipped('Set P24_SANDBOX=1, P24_SANDBOX_MERCHANT_ID, P24_SANDBOX_CRC_KEY and P24_SANDBOX_API_KEY to run the sandbox tests.');
        }

        parent::setUp();
    }

    public function test_transaction_is_registered_and_an_unpaid_transaction_keeps_the_payment_new(): void
    {
        $payment = $this->createPlacedOrder();

        $redirect = $this->payInShop();
        self::assertStringStartsWith('https://sandbox.przelewy24.pl/trnRequest/', $redirect);
        fwrite(STDERR, sprintf("\nPrzelewy24 payment page: %s\n", $redirect));

        $sessionId = $this->details($payment)->getCurrentSessionId();
        self::assertIsString($sessionId);

        // Assumption checked here: a registered, unpaid transaction is reported with status 0 (or not found).
        $this->dispatchPaymentRequest($payment, 'status');
        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertContains($this->details($payment)->getTransactionStatus($sessionId), [Transaction::STATUS_NO_PAYMENT]);
    }

    public function test_notification_with_a_wrong_sign_is_rejected(): void
    {
        $this->createPlacedOrder();
        $this->payInShop();

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify('{"merchantId":1,"sign":"x"}')->getStatusCode());
    }

    protected function p24Credentials(): array
    {
        return [Przelewy24Gateway::CONFIG_ENVIRONMENT => 'sandbox'] + (SandboxEnvironment::credentials() ?? []);
    }
}
