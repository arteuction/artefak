<?php

declare(strict_types=1);

namespace App\Domain\Financial;

use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Runs a financial reconciliation for a given date range.
 *
 * Internal side: reads settled amounts from `settlements` and `transfer_outbox`.
 * Stripe side: accepts externally fetched Stripe Balance/Payout totals as
 *   parameters — this action does NOT call Stripe directly.
 *   The caller (webhook handler / artisan command) fetches from Stripe and passes
 *   the numbers here.  This keeps the domain action testable without Stripe.
 *
 * Match condition: delta_received == 0 AND delta_transferred == 0.
 * Any non-zero delta → status 'mismatched', which must be investigated.
 */
final class RunReconciliation
{
    public function execute(
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        int                $stripeReceivedCents,
        int                $stripeTransferredCents,
        ?User              $runBy = null,
    ): ReconciliationRun {
        $run = ReconciliationRun::create([
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end'   => $periodEnd->format('Y-m-d'),
            'run_by'       => $runBy?->id,
        ]);

        // ── Internal totals ──────────────────────────────────────────────
        $internalRow = DB::table('settlements')
            ->whereBetween('created_at', [$periodStart->format('Y-m-d').' 00:00:00', $periodEnd->format('Y-m-d').' 23:59:59'])
            ->selectRaw('COALESCE(SUM(gross_cents), 0) as gross, COUNT(*) as cnt')
            ->first();

        $internalGross = (int) $internalRow->gross;
        $internalCount = (int) $internalRow->cnt;

        // Completed transfers from outbox
        $internalTransferred = (int) DB::table('transfer_outbox')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$periodStart->format('Y-m-d').' 00:00:00', $periodEnd->format('Y-m-d').' 23:59:59'])
            ->sum('amount_cents');

        // ── Deltas ───────────────────────────────────────────────────────
        $deltaReceived    = $internalGross       - $stripeReceivedCents;
        $deltaTransferred = $internalTransferred - $stripeTransferredCents;

        $status = ($deltaReceived === 0 && $deltaTransferred === 0) ? 'matched' : 'mismatched';

        $run->update([
            'internal_gross_cents'      => $internalGross,
            'internal_transfer_cents'   => $internalTransferred,
            'internal_settlement_count' => $internalCount,
            'stripe_received_cents'     => $stripeReceivedCents,
            'stripe_transferred_cents'  => $stripeTransferredCents,
            'delta_received_cents'      => $deltaReceived,
            'delta_transferred_cents'   => $deltaTransferred,
            'status'                    => $status,
            'completed_at'              => now(),
        ]);

        return $run->fresh();
    }
}
