<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Settlement\ProcessRefund;
use App\Domain\Library\FinalizePaidBookPurchase;
use App\Domain\Payment\HandleConnectedAccountPayout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class HandleStripeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(private int $webhookEventId) {}

    public function handle(ProcessRefund $processRefund, FinalizePaidBookPurchase $finalizeBook, HandleConnectedAccountPayout $handlePayout): void
    {
        $row = DB::table('webhook_events')->find($this->webhookEventId);

        if (! $row || $row->status !== 'received') {
            return;
        }

        DB::table('webhook_events')
            ->where('id', $this->webhookEventId)
            ->update(['status' => 'processing', 'updated_at' => now()]);

        try {
            $event = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $this->route($event, $processRefund, $finalizeBook, $handlePayout);

            DB::table('webhook_events')
                ->where('id', $this->webhookEventId)
                ->update(['status' => 'processed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Reset to 'received' so the next retry attempt can process it.
            // Leaving status as 'processing' or 'failed' would make all retries
            // hit the early-return guard above and silently do nothing.
            DB::table('webhook_events')
                ->where('id', $this->webhookEventId)
                ->update(['status' => 'received', 'error' => $e->getMessage(), 'updated_at' => now()]);

            throw $e;
        }
    }

    private function route(array $event, ProcessRefund $processRefund, FinalizePaidBookPurchase $finalizeBook, HandleConnectedAccountPayout $handlePayout): void
    {
        match ($event['type']) {
            'charge.refunded'               => $this->handleChargeRefunded($event, $processRefund),
            'payment_intent.succeeded'      => $this->handlePaymentIntentSucceeded($event, $finalizeBook),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event),
            'payout.paid'                   => $this->handlePayoutEvent($event, 'paid', $handlePayout),
            'payout.failed'                 => $this->handlePayoutEvent($event, 'failed', $handlePayout),
            'payout.canceled'               => $this->handlePayoutEvent($event, 'canceled', $handlePayout),
            default                         => null,
        };
    }

    private function handleChargeRefunded(array $event, ProcessRefund $processRefund): void
    {
        $charge  = $event['data']['object'];
        $refunds = $charge['refunds']['data'] ?? [];

        foreach ($refunds as $refund) {
            $processRefund->execute(
                stripeRefundId:  $refund['id'],
                paymentIntentId: $charge['payment_intent'],
                amountCents:     (int) $refund['amount'],
                currency:        strtoupper($refund['currency']),
                refundStatus:    $refund['status'] ?? 'succeeded',
                chargeId:        $charge['id'] ?? null,
            );
        }
    }

    private function handlePaymentIntentSucceeded(array $event, FinalizePaidBookPurchase $finalizeBook): void
    {
        $pi   = $event['data']['object'];
        $piId = $pi['id'] ?? null;

        if (! $piId) {
            return;
        }

        // Route book purchases through FinalizePaidBookPurchase (grants entitlement, creates settlement).
        $bookPurchase = DB::table('book_purchases')
            ->where('stripe_payment_intent_id', $piId)
            ->whereIn('status', ['pending'])
            ->first();

        if ($bookPurchase !== null) {
            $finalizeBook->execute(
                stripePaymentIntentId: $piId,
                stripeEventId:        $event['id'],
                amountCents:          (int) ($pi['amount_received'] ?? $pi['amount']),
                currency:             strtoupper($pi['currency']),
            );
            return;
        }

        // Non-book payment: mark any matching settlement as completed.
        DB::table('settlements')
            ->where('stripe_payment_intent_id', $piId)
            ->where('status', 'pending')
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    private function handlePayoutEvent(array $event, string $status, HandleConnectedAccountPayout $handlePayout): void
    {
        $payout    = $event['data']['object'];
        $payoutId  = $payout['id'] ?? null;
        $accountId = $event['account'] ?? null; // present on connected-account events

        if (! $payoutId || ! $accountId) {
            return;
        }

        $handlePayout->execute(
            stripePayoutId:  $payoutId,
            stripeAccountId: $accountId,
            stripeEventId:   $event['id'],
            amountCents:     (int) ($payout['amount'] ?? 0),
            currency:        strtoupper($payout['currency'] ?? 'BGN'),
            status:          $status,
            failureCode:     $payout['failure_code'] ?? null,
            failureMessage:  $payout['failure_message'] ?? null,
            arrivalDate:     isset($payout['arrival_date']) ? (int) $payout['arrival_date'] : null,
        );
    }

    private function handlePaymentFailed(array $event): void
    {
        $pi = $event['data']['object'];
        $piId = $pi['id'] ?? null;

        if (! $piId) {
            return;
        }

        // Record failure reason for ops visibility; settlement stays pending until manual review
        DB::table('settlements')
            ->where('stripe_payment_intent_id', $piId)
            ->whereIn('status', ['pending'])
            ->update([
                'status'     => 'pending',
                'updated_at' => now(),
            ]);
    }
}
