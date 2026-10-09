<?php

declare(strict_types=1);

namespace App\Domain\Financial;

use App\Models\ReconciliationMismatch;
use App\Models\ReconciliationRun;
use Illuminate\Support\Facades\DB;

/**
 * Per-type reconciliation sub-checks.
 *
 * Each check compares a slice of the internal ledger against the Stripe
 * transactions passed by the caller. Mismatches are persisted individually
 * in reconciliation_mismatches so the operations team can trace each
 * discrepancy back to a specific Stripe transaction ID.
 *
 * Caller is responsible for fetching Stripe data; this class is Stripe-free
 * and fully unit-testable.
 *
 * Check types
 * -----------
 *   payment   — captured PaymentIntents in settlements vs Stripe charge transactions
 *   refund    — refunded settlements vs Stripe refund transactions
 *   transfer  — dispatched transfer_outbox rows vs Stripe transfer transactions
 *
 * Return value
 * ------------
 *   int  Total number of mismatches recorded across all sub-checks.
 */
final class ReconciliationSubChecks
{
    /**
     * @param  ReconciliationRun  $run
     * @param  array<string,int>  $stripeCharges    stripe_payment_intent_id => amount_cents
     * @param  array<string,int>  $stripeRefunds    stripe_charge_id/pi_id   => refunded_cents
     * @param  array<string,int>  $stripeTransfers  stripe_transfer_id       => amount_cents
     * @param  string             $periodStart      'Y-m-d'
     * @param  string             $periodEnd        'Y-m-d'
     */
    public function run(
        ReconciliationRun $run,
        array             $stripeCharges,
        array             $stripeRefunds,
        array             $stripeTransfers,
        string            $periodStart,
        string            $periodEnd,
    ): int {
        $total = 0;
        $total += $this->checkPayments($run, $stripeCharges, $periodStart, $periodEnd);
        $total += $this->checkRefunds($run, $stripeRefunds, $periodStart, $periodEnd);
        $total += $this->checkTransfers($run, $stripeTransfers, $periodStart, $periodEnd);
        return $total;
    }

    /**
     * Payment check: every completed settlement should have a matching Stripe charge.
     * And every Stripe charge should correspond to a settlement.
     */
    private function checkPayments(
        ReconciliationRun $run,
        array             $stripeCharges,
        string            $periodStart,
        string            $periodEnd,
    ): int {
        $mismatches = 0;

        // Internal: settlements completed in the period
        $internal = DB::table('settlements')
            ->whereBetween('created_at', ["{$periodStart} 00:00:00", "{$periodEnd} 23:59:59"])
            ->whereNotIn('status', ['refunded'])
            ->select('stripe_payment_intent_id', 'gross_cents')
            ->get()
            ->keyBy('stripe_payment_intent_id');

        // Check each internal settlement has a matching Stripe charge
        foreach ($internal as $piId => $row) {
            $stripeCents = $stripeCharges[$piId] ?? null;

            if ($stripeCents === null) {
                $this->record($run, 'payment', null, $piId, null, (int) $row->gross_cents,
                    -(int) $row->gross_cents,
                    "Settlement {$piId} has no Stripe charge in the period");
                $mismatches++;
                continue;
            }

            $delta = (int) $row->gross_cents - $stripeCents;
            if ($delta !== 0) {
                $this->record($run, 'payment', $piId, $piId, $stripeCents, (int) $row->gross_cents,
                    $delta,
                    "Amount mismatch for PI {$piId}: internal={$row->gross_cents} stripe={$stripeCents}");
                $mismatches++;
            }
        }

        // Check each Stripe charge has a matching settlement
        foreach ($stripeCharges as $piId => $stripeCents) {
            if (! $internal->has($piId)) {
                $this->record($run, 'payment', $piId, null, $stripeCents, null,
                    $stripeCents,
                    "Stripe charge {$piId} has no internal settlement");
                $mismatches++;
            }
        }

        return $mismatches;
    }

    /**
     * Refund check: refunded settlements should have a matching Stripe refund.
     */
    private function checkRefunds(
        ReconciliationRun $run,
        array             $stripeRefunds,
        string            $periodStart,
        string            $periodEnd,
    ): int {
        $mismatches = 0;

        $refunded = DB::table('settlements')
            ->whereBetween('updated_at', ["{$periodStart} 00:00:00", "{$periodEnd} 23:59:59"])
            ->where('status', 'refunded')
            ->select('stripe_payment_intent_id', 'gross_cents')
            ->get()
            ->keyBy('stripe_payment_intent_id');

        foreach ($refunded as $piId => $row) {
            $stripeRefundCents = $stripeRefunds[$piId] ?? null;

            if ($stripeRefundCents === null) {
                $this->record($run, 'refund', null, $piId, null, (int) $row->gross_cents,
                    -(int) $row->gross_cents,
                    "Refunded settlement {$piId} has no Stripe refund in the period");
                $mismatches++;
                continue;
            }

            $delta = (int) $row->gross_cents - $stripeRefundCents;
            if ($delta !== 0) {
                $this->record($run, 'refund', $piId, $piId, $stripeRefundCents, (int) $row->gross_cents,
                    $delta,
                    "Refund amount mismatch for PI {$piId}: internal={$row->gross_cents} stripe={$stripeRefundCents}");
                $mismatches++;
            }
        }

        foreach ($stripeRefunds as $piId => $stripeRefundCents) {
            if (! $refunded->has($piId)) {
                $this->record($run, 'refund', $piId, null, $stripeRefundCents, null,
                    $stripeRefundCents,
                    "Stripe refund for {$piId} has no refunded internal settlement");
                $mismatches++;
            }
        }

        return $mismatches;
    }

    /**
     * Transfer check: dispatched transfer_outbox rows should have Stripe transfer IDs.
     * Each Stripe transfer should correspond to a dispatched outbox row.
     */
    private function checkTransfers(
        ReconciliationRun $run,
        array             $stripeTransfers,
        string            $periodStart,
        string            $periodEnd,
    ): int {
        $mismatches = 0;

        $dispatched = DB::table('transfer_outbox')
            ->where('status', 'dispatched')
            ->whereNotNull('stripe_transfer_id')
            ->whereBetween('dispatched_at', ["{$periodStart} 00:00:00", "{$periodEnd} 23:59:59"])
            ->select('stripe_transfer_id', 'amount_cents')
            ->get()
            ->keyBy('stripe_transfer_id');

        foreach ($dispatched as $transferId => $row) {
            $stripeCents = $stripeTransfers[$transferId] ?? null;

            if ($stripeCents === null) {
                $this->record($run, 'transfer', null, $transferId, null, (int) $row->amount_cents,
                    -(int) $row->amount_cents,
                    "Transfer {$transferId} dispatched internally but not found on Stripe");
                $mismatches++;
                continue;
            }

            $delta = (int) $row->amount_cents - $stripeCents;
            if ($delta !== 0) {
                $this->record($run, 'transfer', $transferId, $transferId, $stripeCents, (int) $row->amount_cents,
                    $delta,
                    "Transfer amount mismatch for {$transferId}: internal={$row->amount_cents} stripe={$stripeCents}");
                $mismatches++;
            }
        }

        foreach ($stripeTransfers as $transferId => $stripeCents) {
            if (! $dispatched->has($transferId)) {
                $this->record($run, 'transfer', $transferId, null, $stripeCents, null,
                    $stripeCents,
                    "Stripe transfer {$transferId} has no dispatched outbox row");
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function record(
        ReconciliationRun $run,
        string            $checkType,
        ?string           $stripeTransactionId,
        ?string           $internalReference,
        ?int              $stripeAmountCents,
        ?int              $internalAmountCents,
        int               $deltaCents,
        string            $description,
    ): void {
        ReconciliationMismatch::create([
            'reconciliation_run_id' => $run->id,
            'check_type'            => $checkType,
            'stripe_transaction_id' => $stripeTransactionId,
            'internal_reference'    => $internalReference,
            'stripe_amount_cents'   => $stripeAmountCents,
            'internal_amount_cents' => $internalAmountCents,
            'delta_cents'           => $deltaCents,
            'description'           => $description,
            'resolution_status'     => 'open',
        ]);
    }
}
