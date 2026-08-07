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
 * Dispatches a single pending transfer_outbox row to Stripe Connect.
 *
 * Design:
 * - Receives the outbox row ID only (not the full row) — avoids stale data.
 * - Uses the stored stripe_idempotency_key so Stripe deduplicates retries.
 * - On success: marks outbox dispatched + fills settlement_line.stripe_transfer_id.
 * - On Stripe API failure: increments attempt, schedules next_attempt_at with
 *   exponential backoff, marks status=failed after MAX_ATTEMPTS.
 * - Does NOT run inside the settlement transaction — called post-commit.
 */
class DispatchStripeTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly int $outboxId,
    ) {}

    public function handle(StripeClient $stripe): void
    {
        $row = DB::table('transfer_outbox')->where('id', $this->outboxId)->lockForUpdate()->first();

        if ($row === null) {
            Log::warning('DispatchStripeTransfer: outbox row not found', ['outbox_id' => $this->outboxId]);
            return;
        }

        if ($row->status !== 'pending') {
            // Already dispatched or dead-lettered by a concurrent worker — skip.
            return;
        }

        try {
            $transfer = $stripe->transfers->create(
                [
                    'amount'      => $row->amount_cents,
                    'currency'    => strtolower($row->currency),
                    'destination' => $row->stripe_account_id,
                ],
                ['idempotency_key' => $row->stripe_idempotency_key]
            );

            DB::transaction(function () use ($row, $transfer): void {
                DB::table('transfer_outbox')->where('id', $row->id)->update([
                    'status'             => 'dispatched',
                    'stripe_transfer_id' => $transfer->id,
                    'dispatched_at'      => now(),
                    'updated_at'         => now(),
                ]);

                DB::table('settlement_lines')->where('id', $row->settlement_line_id)->update([
                    'stripe_transfer_id' => $transfer->id,
                    'status'             => 'transferred',
                    'updated_at'         => now(),
                ]);
            });

        } catch (ApiErrorException $e) {
            $attempt = (int) $row->attempt + 1;
            $isFinal = $attempt >= self::MAX_ATTEMPTS;

            // Exponential backoff: 2^attempt minutes (2, 4, 8, 16, 32 min)
            $nextAttemptAt = $isFinal ? null : now()->addMinutes(2 ** $attempt);

            DB::table('transfer_outbox')->where('id', $row->id)->update([
                'status'          => $isFinal ? 'failed' : 'pending',
                'attempt'         => $attempt,
                'last_error'      => $e->getMessage(),
                'next_attempt_at' => $nextAttemptAt,
                'updated_at'      => now(),
            ]);

            Log::error('DispatchStripeTransfer: Stripe API error', [
                'outbox_id' => $row->id,
                'attempt'   => $attempt,
                'final'     => $isFinal,
                'error'     => $e->getMessage(),
            ]);

            if (!$isFinal) {
                // Re-queue with delay so the queue worker doesn't spin immediately.
                self::dispatch($this->outboxId)->delay($nextAttemptAt);
            }

            // Do not re-throw — the job is not "failed" from Laravel's perspective;
            // we manage state ourselves to avoid duplicate jobs from Laravel's retry.
        }
    }
}
