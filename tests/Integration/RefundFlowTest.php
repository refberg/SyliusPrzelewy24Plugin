<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Integration;

use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use function array_values;
use function json_decode;
use function json_encode;
use function str_replace;
use const JSON_THROW_ON_ERROR;

final class RefundFlowTest extends IntegrationTestCase
{
    public function test_admin_refund_requests_a_single_full_refund_at_przelewy24(): void
    {
        $payment = $this->paidPayment();
        $sessionId = $this->p24->lastSessionId();
        $orderId = $this->p24->transaction($sessionId)['orderId'];

        $this->refundInAdmin($payment);

        $calls = $this->p24->requests('POST', '#^/api/v1/transaction/refund$#');
        self::assertCount(1, $calls);
        $body = json_decode($calls[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        $requestId = (string) $requests[0]->getId();

        self::assertSame([
            'requestId' => $requestId,
            'refunds' => [[
                'orderId' => $orderId,
                'sessionId' => $sessionId,
                'amount' => 1500,
                'description' => 'Refund 000000001',
            ]],
            'refundsUuid' => str_replace('-', '', $requestId),
            'urlStatus' => 'http://localhost/payment-methods/P24',
        ], $body);

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $refunds = $this->details($payment)->refunds();
        self::assertCount(1, $refunds);
        self::assertSame(Refund::STATUS_PENDING, array_values($refunds)[0]['status']);
    }

    public function test_refund_notification_records_the_final_status(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);
        $refundsUuid = (string) $this->p24->refunds()[0]['refundsUuid'];

        $body = $this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_COMPLETED);
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        // Duplicate delivery.
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());

        $refunds = $this->details($payment)->refunds();
        self::assertSame(Refund::STATUS_COMPLETED, $refunds[$refundsUuid]['status'] ?? null);
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_in_progress_rejected_by_przelewy24_is_recorded_and_the_payment_stays_refunded(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);
        $refundsUuid = (string) $this->p24->refunds()[0]['refundsUuid'];

        $body = $this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_REJECTED);
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());

        $details = $this->details($payment);
        self::assertSame(Refund::STATUS_REJECTED, $details->refunds()[$refundsUuid]['status'] ?? null);
        self::assertFalse($details->hasActiveRefund());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());

        // A late "completed" notification does not overwrite the final status.
        $completed = $this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_COMPLETED);
        self::assertSame(Response::HTTP_OK, $this->notify($completed)->getStatusCode());
        self::assertSame(Refund::STATUS_REJECTED, $this->details($payment)->refunds()[$refundsUuid]['status'] ?? null);
    }

    public function test_refund_notification_with_wrong_sign_is_rejected(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);
        $refundsUuid = (string) $this->p24->refunds()[0]['refundsUuid'];

        $body = $this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_REJECTED, crcKey: 'wrong');
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body)->getStatusCode());

        self::assertSame(Refund::STATUS_PENDING, array_values($this->details($payment)->refunds())[0]['status']);
    }

    #[TestWith([400, 'Request already exists'])]
    #[TestWith([409, 'Insufficient funds available'])]
    #[TestWith([503, 'Unknown error'])]
    public function test_failed_refund_call_is_recorded_on_the_payment_request(int $httpStatus, string $error): void
    {
        $payment = $this->paidPayment();
        $this->p24->failNextRequest($httpStatus, $error);

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString($error, (string) json_encode($requests[0]->getResponseData()));
        self::assertSame([], $this->details($payment)->refunds());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_request_for_a_payment_that_was_not_refunded_in_sylius_never_reaches_przelewy24(): void
    {
        $payment = $this->paidPayment();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_REFUND);

        self::assertSame([], $this->p24->requests('POST', '#/refund$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be refunded.'], $requests[0]->getResponseData());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_admin_refund_after_a_partial_panel_refund_requests_only_the_remaining_amount(): void
    {
        $payment = $this->paidPayment();
        $panelRefund = $this->p24->createPanelRefund($this->p24->lastSessionId(), 500);
        self::assertSame(Response::HTTP_OK, $this->notify($this->p24->refundNotificationBody($panelRefund, Refund::NOTIFICATION_STATUS_COMPLETED))->getStatusCode());

        $this->refundInAdmin($payment);

        $calls = $this->p24->requests('POST', '#/refund$#');
        self::assertCount(1, $calls);
        $sent = json_decode($calls[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame(1000, self::at($sent, 'refunds', '0', 'amount'));

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
    }

    public function test_admin_refund_after_panel_refunds_covering_the_payment_does_not_call_przelewy24(): void
    {
        $payment = $this->paidPayment();
        $sessionId = $this->p24->lastSessionId();
        foreach ([700, 800] as $amount) {
            $panelRefund = $this->p24->createPanelRefund($sessionId, $amount);
            self::assertSame(Response::HTTP_OK, $this->notify($this->p24->refundNotificationBody($panelRefund, Refund::NOTIFICATION_STATUS_COMPLETED))->getStatusCode());
        }

        // Both completed panel refunds cover the payment, which is then refunded without another refund request.
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->p24->requests('POST', '#/refund$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_external_refunds'));
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $this->payAndNotify();

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());

        return $payment;
    }

    /**
     * Same transition the admin "Refund" button applies.
     */
    private function refundInAdmin(PaymentInterface $payment): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();
    }
}
