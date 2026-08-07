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
 * Lease pattern (prevents holding a DB lock during Stripe I/O):
 *   1. Short TX: pending → processing + processing_started_at = now()  [commit]
 *   2. Stripe transfers.create() OUTSIDE any transaction
 *   3. Short TX: processing → dispatched  (or → pending with backoff on failure)
 *
 * Abandoned leases: any row with status=processing AND
 * processing_started_at < now() - 5min should be reset to pending.
 * See ReclaimAbandonedTransfers artisan command.
 */
class DispatchStripeTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS   = 5;
    private const LEASE_MINUTES  = 5;

    public function __construct(
        public readonly int $outboxId,
    ) {}

    public function handle(StripeClient $stripe): void
    {
        // ── Step 1: Claim the row (short TX, no Stripe I/O) ──────────────
        $claimed = DB::transaction(function (): bool {
            $row = DB::table('transfer_outbox')
                ->where('id', $this->outboxId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false; // already processing/dispatched/canceled/failed
            }

            DB::table('transfer_outbox')->where('id', $this->outboxId)->update([
                'status'                => 'processing',
                'processing_started_at' => now(),
                'updated_at'            => now(),
            ]);

            return true;
        });

        if (!$claimed) {
            return;
        }

        // Re-read outside the transaction to get fresh data for Stripe
        $row = DB::table('transfer_outbox')->find($this->outboxId);

        // ── Step 2: Stripe API OUTSIDE transaction ────────────────────────
        try {
            $transfer = $stripe->transfers->create(
                [
                    'amount'      => $row->amount_cents,
                    'currency'    => strtolower($row->currency),
                    'destination' => $row->stripe_account_id,
                ],
                ['idempotency_key' => $row->stripe_idempotency_key]
            );

            // ── Step 3a: Success ─────────────────────────────────────────
            DB::transaction(function () use ($row, $transfer): void {
                DB::table('transfer_outbox')->where('id', $row->id)->update([
                    'status'                => 'dispatched',
                    'stripe_transfer_id'    => $transfer->id,
                    'dispatched_at'         => now(),
                    'processing_started_at' => null,
                    'updated_at'            => now(),
                ]);

                DB::table('settlement_lines')->where('id', $row->settlement_line_id)->update([
                    'stripe_transfer_id' => $transfer->id,
                    'status'             => 'transferred',
                    'updated_at'         => now(),
                ]);
            });

        } catch (ApiErrorException $e) {
            // ── Step 3b: Failure — release lease, schedule retry ─────────
            $attempt = (int) $row->attempt + 1;
            $isFinal = $attempt >= self::MAX_ATTEMPTS;
            $nextAt  = $isFinal ? null : now()->addMinutes(2 ** $attempt);

            DB::table('transfer_outbox')->where('id', $row->id)->update([
                'status'                => $isFinal ? 'failed' : 'pending',
                'attempt'               => $attempt,
                'last_error'            => $e->getMessage(),
                'next_attempt_at'       => $nextAt,
                'processing_started_at' => null,
                'updated_at'            => now(),
            ]);

            Log::error('DispatchStripeTransfer: Stripe API error', [
                'outbox_id' => $row->id,
                'attempt'   => $attempt,
                'final'     => $isFinal,
                'error'     => $e->getMessage(),
            ]);

            if (!$isFinal) {
                self::dispatch($this->outboxId)->delay($nextAt);
            }
        }
    }
}
