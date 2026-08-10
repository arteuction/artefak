<?php

namespace App\Jobs;

use App\Application\Settlement\ProcessRefund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class HandleStripeWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $webhookEventId) {}

    public function handle(ProcessRefund $processRefund): void
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
            $this->route($event, $processRefund);

            DB::table('webhook_events')
                ->where('id', $this->webhookEventId)
                ->update(['status' => 'processed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('webhook_events')
                ->where('id', $this->webhookEventId)
                ->update([
                    'status'     => 'failed',
                    'error'      => $e->getMessage(),
                    'updated_at' => now(),
                ]);

            throw $e;
        }
    }

    private function route(array $event, ProcessRefund $processRefund): void
    {
        match ($event['type']) {
            'charge.refunded'               => $this->handleChargeRefunded($event, $processRefund),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event),
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

    private function handlePaymentFailed(array $event): void
    {
        // Phase 2: update payment attempt status
    }
}
