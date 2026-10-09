<?php

declare(strict_types=1);

namespace App\Domain\Financial;

use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type StripeTransactionMap array<string,int>
 */

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
    /**
     * @param  array<string,int>|null  $stripeCharges    PI ID → captured cents; null = sub-check skipped
     * @param  array<string,int>|null  $stripeRefunds    PI ID → refunded cents; null = sub-check skipped
     * @param  array<string,int>|null  $stripeTransfers  Transfer ID → amount cents; null = sub-check skipped
     */
    public function execute(
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        int                $stripeReceivedCents,
        int                $stripeTransferredCents,
        ?User              $runBy = null,
        ?array             $stripeCharges   = null,
        ?array             $stripeRefunds   = null,
        ?array             $stripeTransfers = null,
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

        // Dispatched transfers from outbox (status 'completed' does not exist — correct is 'dispatched')
        $internalTransferred = (int) DB::table('transfer_outbox')
            ->where('status', 'dispatched')
            ->whereBetween('dispatched_at', [$periodStart->format('Y-m-d').' 00:00:00', $periodEnd->format('Y-m-d').' 23:59:59'])
            ->sum('amount_cents');

        // ── Deltas ───────────────────────────────────────────────────────
        $deltaReceived    = $internalGross       - $stripeReceivedCents;
        $deltaTransferred = $internalTransferred - $stripeTransferredCents;

        // Per-type sub-checks — only when the caller provides detailed Stripe maps
        // (null means "not provided"; an empty array means "provided but empty").
        $mismatchCount = 0;
        if ($stripeCharges !== null || $stripeRefunds !== null || $stripeTransfers !== null) {
            $mismatchCount = (new ReconciliationSubChecks())->run(
                run:             $run,
                stripeCharges:   $stripeCharges   ?? [],
                stripeRefunds:   $stripeRefunds   ?? [],
                stripeTransfers: $stripeTransfers ?? [],
                periodStart:     $periodStart->format('Y-m-d'),
                periodEnd:       $periodEnd->format('Y-m-d'),
            );
        }

        $status = ($deltaReceived === 0 && $deltaTransferred === 0 && $mismatchCount === 0)
            ? 'matched'
            : 'mismatched';

        $run->update([
            'internal_gross_cents'      => $internalGross,
            'internal_transfer_cents'   => $internalTransferred,
            'internal_settlement_count' => $internalCount,
            'stripe_received_cents'     => $stripeReceivedCents,
            'stripe_transferred_cents'  => $stripeTransferredCents,
            'delta_received_cents'      => $deltaReceived,
            'delta_transferred_cents'   => $deltaTransferred,
            'mismatch_count'            => $mismatchCount,
            'status'                    => $status,
            'completed_at'              => now(),
        ]);

        return $run->fresh();
    }
}
