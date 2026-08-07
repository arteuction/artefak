<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Reverses a Stripe Connect transfer for a single settlement line.
 *
 * - Reads settlement_lines.stripe_transfer_id to call Stripe.
 * - Uses stripe_refund_id as part of the idempotency key so Stripe
 *   deduplicates retries for this specific refund event.
 * - On success: settlement_line.status = 'reversed' + ledger debit entry.
 * - On failure: exponential backoff up to MAX_ATTEMPTS, then dead-letter.
 */
class ReverseStripeTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly int    $lineId,
        public readonly string $stripeRefundId,
    ) {}

    public function handle(StripeClient $stripe): void
    {
        $line = DB::table('settlement_lines')->where('id', $this->lineId)->lockForUpdate()->first();

        if ($line === null) {
            Log::warning('ReverseStripeTransfer: line not found', ['line_id' => $this->lineId]);
            return;
        }

        // Already reversed — idempotent skip
        if ($line->status === 'reversed') {
            return;
        }

        if ($line->stripe_transfer_id === null) {
            Log::warning('ReverseStripeTransfer: no stripe_transfer_id on line', ['line_id' => $this->lineId]);
            return;
        }

        $idempotencyKey = 'rev_' . $this->stripeRefundId . '_line_' . $this->lineId;

        try {
            $stripe->transfers->createReversal(
                $line->stripe_transfer_id,
                ['amount' => (int) $line->amount_cents],
                ['idempotency_key' => $idempotencyKey],
            );

            DB::transaction(function () use ($line): void {
                DB::table('settlement_lines')->where('id', $line->id)->update([
                    'status'     => 'reversed',
                    'updated_at' => now(),
                ]);

                // Ledger debit for fund lines (append-only balance correction)
                if ($line->recipient_type === 'fund') {
                    DB::table('ledger_entries')->insert([
                        'settlement_id'      => $line->settlement_id,
                        'settlement_line_id' => $line->id,
                        'type'               => 'debit',
                        'amount_cents'       => (int) $line->amount_cents,
                        'currency'           => $line->currency,
                        'note'               => 'Refund reversal',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            });

        } catch (ApiErrorException $e) {
            // Track retries on the line itself (no separate outbox for reversals in P0)
            Log::error('ReverseStripeTransfer: Stripe API error', [
                'line_id' => $this->lineId,
                'error'   => $e->getMessage(),
            ]);

            // Re-throw so Laravel's built-in retry / failed-jobs mechanism takes over.
            // We set $tries = MAX_ATTEMPTS on the job class via $tries property.
            throw $e;
        }
    }

    /** Laravel will retry up to this many times before moving to failed_jobs. */
    public int $tries = self::MAX_ATTEMPTS;

    /** Exponential backoff: 2, 4, 8, 16, 32 seconds between retries. */
    public function backoff(): array
    {
        return [2, 4, 8, 16, 32];
    }
}
