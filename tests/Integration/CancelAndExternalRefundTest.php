<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Integration;

use Refberg\SyliusPrzelewy24Plugin\Api\Model\Refund;
use Refberg\SyliusPrzelewy24Plugin\Api\Model\Transaction;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;

final class CancelAndExternalRefundTest extends IntegrationTestCase
{
    public function test_transaction_of_a_payment_cancelled_in_sylius_is_never_verified(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertSame(['cancelled_session_ids' => [$sessionId]], $requests[0]->getResponseData());
        self::assertTrue($this->details($payment)->isCancelled($sessionId));

        // The customer pays the old transaction anyway: it is not verified, so Przelewy24 does not settle it.
        $this->p24->pay($sessionId);
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($sessionId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('cancelled', $payment->getState());
        self::assertSame([['type' => PaymentDetails::WARNING_LATE_PAYMENT, 'session_id' => $sessionId]], PaymentDetails::of($payment)->warnings());
        self::assertSame([], $this->p24->requests('PUT'));
        self::assertSame(Transaction::STATUS_ADVANCE_PAYMENT, $this->p24->transaction($sessionId)['status']);
    }

    public function test_cancel_request_for_a_payment_that_was_not_cancelled_in_sylius_changes_nothing(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $sessionId = $this->p24->lastSessionId();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertFalse($this->details($payment)->isCancelled($sessionId));
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        $this->payAndNotify();
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_full_refund_made_in_the_przelewy24_panel_marks_the_payment_refunded(): void
    {
        $payment = $this->paidPayment();
        $refundsUuid = $this->p24->createPanelRefund($this->p24->lastSessionId(), 1500);

        $body = $this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_COMPLETED);
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());

        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        // Sylius must not ask Przelewy24 for a second refund.
        self::assertSame([], $this->p24->requests('POST', '#/refund$#'));
        $details = $this->details($payment);
        self::assertSame(1500, $details->completedRefundAmount());
        self::assertTrue(self::at($details->refunds(), $refundsUuid, 'external'));
        self::assertFalse($details->hasActiveRefund());
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_external_refunds'));
    }

    public function test_partial_refund_made_in_the_przelewy24_panel_is_only_recorded(): void
    {
        $payment = $this->paidPayment();
        $sessionId = $this->p24->lastSessionId();
        $refundsUuid = $this->p24->createPanelRefund($sessionId, 500);

        self::assertSame(Response::HTTP_OK, $this->notify($this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_COMPLETED))->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
        self::assertSame(500, $this->details($payment)->completedRefundAmount());
        self::assertSame([], $this->p24->requests('POST', '#/refund$#'));
    }

    public function test_rejected_panel_refund_does_not_change_the_payment(): void
    {
        $payment = $this->paidPayment();
        $refundsUuid = $this->p24->createPanelRefund($this->p24->lastSessionId(), 1500);

        self::assertSame(Response::HTTP_OK, $this->notify($this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_REJECTED))->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
        $details = $this->details($payment);
        self::assertSame(0, $details->completedRefundAmount());
        self::assertSame(0, $details->activeRefundAmount());
    }

    public function test_refund_notification_in_another_currency_is_ignored(): void
    {
        $payment = $this->paidPayment();
        $sessionId = $this->p24->lastSessionId();
        $refundsUuid = $this->p24->createPanelRefund($sessionId, 1500);
        $this->p24->overrideTransaction($sessionId, 'currency', 'EUR');

        self::assertSame(Response::HTTP_OK, $this->notify($this->p24->refundNotificationBody($refundsUuid, Refund::NOTIFICATION_STATUS_COMPLETED))->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->details($payment)->refunds());
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $this->payAndNotify();
        self::assertSame('completed', $this->reloadPayment($payment)->getState());

        return $payment;
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
