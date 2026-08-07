<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Jobs\ReverseStripeTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Processes a full refund for a completed settlement.
 *
 * Call sequence (inside one transaction):
 *   1. Load the settlement — bail early if already refunded.
 *   2. Mark settlement.status = 'refunded'.
 *   3. Cancel any pending outbox rows (they have not hit Stripe yet).
 *   4. Collect dispatched lines (stripe_transfer_id filled) for reversal.
 *   5. Commit.
 *   6. Dispatch ReverseStripeTransfer for each dispatched line (post-commit).
 *
 * Idempotent: calling twice with the same settlement ID is safe — the
 * status check in step 1 short-circuits on the second call.
 *
 * P0 scope: full refund only. Partial refund is a separate slice.
 */
final class ProcessRefund
{
    /**
     * @param  int    $settlementId
     * @param  string $stripeRefundId  From Stripe event — stored for audit.
     * @return bool   false if settlement was already refunded (idempotent skip)
     */
    public function execute(int $settlementId, string $stripeRefundId): bool
    {
        $dispatchedLineIds = [];

        $processed = DB::transaction(function () use ($settlementId, $stripeRefundId, &$dispatchedLineIds): bool {
            $settlement = DB::table('settlements')
                ->where('id', $settlementId)
                ->lockForUpdate()
                ->first();

            if ($settlement === null) {
                throw new \DomainException("Settlement {$settlementId} not found.");
            }

            // Idempotency: already refunded — nothing to do
            if (in_array($settlement->status, ['refunded', 'partially_refunded'], true)) {
                return false;
            }

            // 1. Mark settlement refunded
            DB::table('settlements')->where('id', $settlementId)->update([
                'status'     => 'refunded',
                'updated_at' => now(),
            ]);

            // 2. Cancel pending outbox rows (not yet dispatched to Stripe)
            DB::table('transfer_outbox')
                ->whereIn('settlement_line_id', function ($q) use ($settlementId) {
                    $q->select('id')->from('settlement_lines')->where('settlement_id', $settlementId);
                })
                ->where('status', 'pending')
                ->update([
                    'status'     => 'failed',
                    'last_error' => 'Cancelled: settlement refunded',
                    'updated_at' => now(),
                ]);

            // 3. Collect dispatched lines that need Stripe reversal
            $dispatchedLineIds = DB::table('settlement_lines')
                ->where('settlement_id', $settlementId)
                ->where('status', 'transferred')
                ->whereNotNull('stripe_transfer_id')
                ->pluck('id')
                ->all();

            return true;
        });

        // 4. Dispatch reversal jobs post-commit (one per dispatched line)
        if ($processed) {
            foreach ($dispatchedLineIds as $lineId) {
                ReverseStripeTransfer::dispatch($lineId, $stripeRefundId);
            }
        }

        return $processed;
    }
}
