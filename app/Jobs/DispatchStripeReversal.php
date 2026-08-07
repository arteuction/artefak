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
 * Executes a single transfer_reversal_orders row via Stripe Connect.
 *
 * Lease pattern — identical to DispatchStripeTransfer:
 *   1. Short TX: pending → processing  [commit]
 *   2. Stripe transfers.createReversal() OUTSIDE transaction
 *   3. Short TX: processing → reversed / back to pending with backoff
 *
 * On success: refund_line.status = 'reversed', settlement_line.status = 'reversed'.
 * If Stripe returns an insufficient-balance error, the connected account needs
 * to top up their balance — operator must intervene (status stays pending/failed).
 */
class DispatchStripeReversal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS  = 5;

    public function __construct(
        public readonly int $reversalOrderId,
    ) {}

    public function handle(StripeClient $stripe): void
    {
        // ── Step 1: Claim the row ────────────────────────────────────────
        $claimed = DB::transaction(function (): bool {
            $order = DB::table('transfer_reversal_orders')
                ->where('id', $this->reversalOrderId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                return false;
            }

            DB::table('transfer_reversal_orders')->where('id', $this->reversalOrderId)->update([
                'status'                => 'processing',
                'processing_started_at' => now(),
                'updated_at'            => now(),
            ]);

            return true;
        });

        if (!$claimed) {
            return;
        }

        $order = DB::table('transfer_reversal_orders')->find($this->reversalOrderId);

        // Load refund_line for linkage
        $refundLine = DB::table('refund_lines')->find($order->refund_line_id);

        // ── Step 2: Stripe API OUTSIDE transaction ───────────────────────
        try {
            $reversal = $stripe->transfers->createReversal(
                $order->stripe_transfer_id,
                ['amount' => (int) $order->amount_cents],
                ['idempotency_key' => $order->idempotency_key]
            );

            // ── Step 3a: Success ────────────────────────────────────────
            DB::transaction(function () use ($order, $refundLine, $reversal): void {
                DB::table('transfer_reversal_orders')->where('id', $order->id)->update([
                    'status'                => 'reversed',
                    'stripe_reversal_id'    => $reversal->id,
                    'reversed_at'           => now(),
                    'processing_started_at' => null,
                    'updated_at'            => now(),
                ]);

                DB::table('refund_lines')->where('id', $order->refund_line_id)->update([
                    'status'     => 'reversed',
                    'updated_at' => now(),
                ]);

                DB::table('settlement_lines')->where('id', $refundLine->settlement_line_id)->update([
                    'status'     => 'reversed',
                    'updated_at' => now(),
                ]);
            });

        } catch (ApiErrorException $e) {
            // ── Step 3b: Failure — release lease, schedule retry ────────
            $attempt = (int) $order->attempt + 1;
            $isFinal = $attempt >= self::MAX_ATTEMPTS;
            $nextAt  = $isFinal ? null : now()->addMinutes(2 ** $attempt);

            DB::table('transfer_reversal_orders')->where('id', $order->id)->update([
                'status'                => $isFinal ? 'failed' : 'pending',
                'attempt'               => $attempt,
                'last_error'            => $e->getMessage(),
                'next_attempt_at'       => $nextAt,
                'processing_started_at' => null,
                'updated_at'            => now(),
            ]);

            Log::error('DispatchStripeReversal: Stripe API error', [
                'order_id' => $order->id,
                'attempt'  => $attempt,
                'final'    => $isFinal,
                'error'    => $e->getMessage(),
            ]);

            if (!$isFinal) {
                self::dispatch($this->reversalOrderId)->delay($nextAt);
            }
        }
    }
}
