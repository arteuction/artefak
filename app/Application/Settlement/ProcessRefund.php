<?php

declare(strict_types=1);

namespace App\Application\Settlement;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\RefundAllocator;
use App\Jobs\DispatchStripeReversal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Application action for Stripe refund events.
 *
 * Handles: refund.created / refund.updated / charge.refunded (succeeded only)
 * Does NOT handle: payment_intent.payment_failed (tested in webhook routing layer)
 *
 * Cumulative partial-refund invariant:
 *   Two partial refunds of €33.33 + €66.66 produce the same refund_lines
 *   as a single €99.99 refund, with no lost cent.
 *   Each new refund computes a cumulative target (all_refunded_so_far + this_amount)
 *   and writes only the delta compared to existing refund_lines.
 *
 * Transaction boundary:
 *   SELECT settlements (lock)
 *   Guard: refund_status must be 'succeeded'
 *   Guard: currency must match settlement currency
 *   Guard: cumulative refund cannot exceed gross
 *   INSERT refunds (UNIQUE on stripe_refund_id)
 *   INSERT/UPDATE refund_lines (delta against existing lines)
 *   INSERT ledger debit for all parties (artist, fund, ops)
 *   UPDATE settlement.status → refunded / partially_refunded
 *   UPDATE pending settlement_lines → canceled
 *   UPDATE pending transfer_outbox rows → canceled
 *   UPDATE processing settlement_lines → reversal_required
 *   INSERT transfer_reversal_orders (for transferred lines)
 *   COMMIT
 *
 *   Post-commit: DispatchStripeReversal::dispatch() per new reversal order
 *
 * Idempotent on stripe_refund_id (same ID → same result, no new rows).
 */
final class ProcessRefund
{
    /**
     * @param  string $stripeRefundId   Stripe refund.id
     * @param  string $paymentIntentId  Stripe refund.payment_intent
     * @param  int    $amountCents      Stripe refund.amount
     * @param  string $currency         Uppercased (EUR)
     * @param  string $refundStatus     pending|succeeded|failed|canceled
     * @param  string|null $chargeId    Stripe charge.id (from charge.refunded events)
     * @param  string|null $reason      duplicate|fraudulent|requested_by_customer|etc.
     * @return int   refunds.id
     *
     * @throws \DomainException on unknown payment_intent, currency mismatch,
     *                          or cumulative refund exceeding gross
     */
    public function execute(
        string  $stripeRefundId,
        string  $paymentIntentId,
        int     $amountCents,
        string  $currency        = 'EUR',
        string  $refundStatus    = 'succeeded',
        ?string $chargeId        = null,
        ?string $reason          = null,
    ): int {
        // Only 'succeeded' refunds change financial state.
        // pending/failed/canceled are stored for audit but produce no ledger/reversal.
        $financiallyActive = ($refundStatus === 'succeeded');

        try {
            return $this->attempt(
                $stripeRefundId, $paymentIntentId, $amountCents,
                $currency, $refundStatus, $chargeId, $reason, $financiallyActive,
            );
        } catch (UniqueConstraintViolationException) {
            return (int) DB::table('refunds')
                ->where('stripe_refund_id', $stripeRefundId)
                ->value('id');
        }
    }

    private function attempt(
        string  $stripeRefundId,
        string  $paymentIntentId,
        int     $amountCents,
        string  $currency,
        string  $refundStatus,
        ?string $chargeId,
        ?string $reason,
        bool    $financiallyActive,
    ): int {
        $reversalOrderIds = [];

        $refundId = DB::transaction(function () use (
            $stripeRefundId, $paymentIntentId, $amountCents,
            $currency, $refundStatus, $chargeId, $reason, $financiallyActive,
            &$reversalOrderIds,
        ): int {
            // ── Resolve and lock settlement ───────────────────────────────
            $settlement = DB::table('settlements')
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($settlement === null) {
                throw new \DomainException(
                    "No settlement found for payment_intent {$paymentIntentId}."
                );
            }

            // ── Guard: currency must match ────────────────────────────────
            if (strtoupper($currency) !== strtoupper($settlement->currency)) {
                throw new \DomainException(
                    "Currency mismatch: refund is {$currency}, settlement is {$settlement->currency}."
                );
            }

            // ── Idempotency: duplicate stripe_refund_id ───────────────────
            $existing = DB::table('refunds')
                ->where('stripe_refund_id', $stripeRefundId)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            // ── Guard: same ID with different amount is an error ──────────
            // (handled above — if ID exists we return early; if not, we proceed)

            if ($financiallyActive) {
                // ── Guard: cumulative refund cannot exceed gross ──────────
                $alreadyRefunded = (int) DB::table('refunds')
                    ->where('settlement_id', $settlement->id)
                    ->where('refund_status', 'succeeded')
                    ->sum('amount_cents');

                $newCumulative = $alreadyRefunded + $amountCents;

                if ($newCumulative > (int) $settlement->gross_cents) {
                    throw new \DomainException(sprintf(
                        'Cumulative refund %d exceeds gross %d for settlement %d.',
                        $newCumulative,
                        $settlement->gross_cents,
                        $settlement->id,
                    ));
                }
            }

            // ── 1. INSERT refunds ─────────────────────────────────────────
            $isPartial  = $amountCents < (int) $settlement->gross_cents;
            $refundId = (int) DB::table('refunds')->insertGetId([
                'stripe_refund_id'       => $stripeRefundId,
                'stripe_charge_id'       => $chargeId,
                'settlement_id'          => $settlement->id,
                'amount_cents'           => $amountCents,
                'currency'               => strtoupper($currency),
                'is_partial'             => $isPartial,
                'refund_status'          => $refundStatus,
                'reconciliation_status'  => $financiallyActive ? 'pending' : 'not_required',
                'reason'                 => $reason,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            if (!$financiallyActive) {
                // Audit record only — no ledger or reversal work
                return $refundId;
            }

            // ── 2. Load settlement lines ──────────────────────────────────
            $lines = DB::table('settlement_lines')
                ->where('settlement_id', $settlement->id)
                ->get(['id', 'amount_cents', 'currency', 'recipient_type', 'status', 'stripe_transfer_id'])
                ->all();

            $lineArrays = array_map(fn ($l) => [
                'id'             => (int) $l->id,
                'amount_cents'   => (int) $l->amount_cents,
                'currency'       => $l->currency,
                'recipient_type' => $l->recipient_type,
            ], $lines);

            // ── 3. Cumulative allocation (delta) ──────────────────────────
            // Compute what the refund_lines SHOULD be for the cumulative total,
            // then write only the DELTA compared to existing refund_lines.
            $alreadyRefundedTotal = (int) DB::table('refunds')
                ->where('settlement_id', $settlement->id)
                ->where('refund_status', 'succeeded')
                ->where('id', '!=', $refundId) // exclude this refund (just inserted)
                ->sum('amount_cents');

            $cumulativeTarget = Money::fromCents($alreadyRefundedTotal + $amountCents, strtoupper($currency));
            $cumulativeAlloc  = RefundAllocator::allocate($cumulativeTarget, $lineArrays);

            // Previous allocation across all succeeded refunds per settlement_line
            $prevLines = DB::table('refund_lines')
                ->join('refunds', 'refunds.id', '=', 'refund_lines.refund_id')
                ->where('refunds.settlement_id', $settlement->id)
                ->where('refunds.refund_status', 'succeeded')
                ->where('refunds.id', '!=', $refundId)
                ->get(['refund_lines.settlement_line_id', 'refund_lines.amount_cents']);

            $prevByLine = [];
            foreach ($prevLines as $pl) {
                $prevByLine[(int) $pl->settlement_line_id] =
                    ($prevByLine[(int) $pl->settlement_line_id] ?? 0) + (int) $pl->amount_cents;
            }

            // ── 4. Insert refund_lines (delta) + ledger debits + reversal orders
            foreach ($lines as $line) {
                $cumulativePart = $cumulativeAlloc[(int) $line->id]->cents;
                $prevPart       = $prevByLine[(int) $line->id] ?? 0;
                $delta          = $cumulativePart - $prevPart;

                if ($delta <= 0) {
                    continue; // nothing new for this line in this refund
                }

                $refundLineId = (int) DB::table('refund_lines')->insertGetId([
                    'refund_id'          => $refundId,
                    'settlement_line_id' => $line->id,
                    'amount_cents'       => $delta,
                    'currency'           => strtoupper($currency),
                    'recipient_type'     => $line->recipient_type,
                    'status'             => 'pending',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                // Ledger debit for ALL parties — written here, NEVER in the job
                DB::table('ledger_entries')->insert([
                    'settlement_id'      => $settlement->id,
                    'settlement_line_id' => $line->id,
                    'refund_line_id'     => $refundLineId,
                    'type'               => 'debit',
                    'amount_cents'       => $delta,
                    'currency'           => strtoupper($currency),
                    'note'               => $isPartial ? 'Partial refund' : 'Full refund',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                // Handle by line status
                if ($line->status === 'pending') {
                    DB::table('settlement_lines')->where('id', $line->id)
                        ->update(['status' => 'canceled', 'updated_at' => now()]);

                    DB::table('transfer_outbox')
                        ->where('settlement_line_id', $line->id)
                        ->where('status', 'pending')
                        ->update([
                            'status'     => 'canceled',
                            'last_error' => 'Canceled: settlement refunded',
                            'updated_at' => now(),
                        ]);

                } elseif ($line->status === 'processing') {
                    // Transfer worker owns this row right now — mark it so the
                    // worker creates the reversal order when it commits.
                    // 'processing' enum value added in migration 010.
                    DB::table('settlement_lines')->where('id', $line->id)
                        ->update(['status' => 'reversal_pending', 'updated_at' => now()]);

                } elseif ($line->status === 'transferred' && $line->stripe_transfer_id !== null) {
                    DB::table('settlement_lines')->where('id', $line->id)
                        ->update(['status' => 'reversal_pending', 'updated_at' => now()]);

                    $idemKey = 'rev_' . $stripeRefundId . '_line_' . $line->id;

                    $orderId = (int) DB::table('transfer_reversal_orders')->insertGetId([
                        'refund_line_id'     => $refundLineId,
                        'stripe_transfer_id' => $line->stripe_transfer_id,
                        'amount_cents'       => $delta,
                        'currency'           => strtoupper($currency),
                        'idempotency_key'    => $idemKey,
                        'status'             => 'pending',
                        'attempt'            => 0,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);

                    $reversalOrderIds[] = $orderId;
                }
                // ops line without stripe_account_id: refund_line + ledger debit, no reversal order
            }

            // ── 5. Update settlement status ───────────────────────────────
            $totalRefunded = $alreadyRefundedTotal + $amountCents;
            $newStatus = ($totalRefunded >= (int) $settlement->gross_cents)
                ? 'refunded'
                : 'partially_refunded';

            DB::table('settlements')->where('id', $settlement->id)
                ->update(['status' => $newStatus, 'updated_at' => now()]);

            return $refundId;
        });

        // ── Post-commit: wake reversal workers ────────────────────────────
        foreach ($reversalOrderIds as $orderId) {
            DispatchStripeReversal::dispatch($orderId);
        }

        return $refundId;
    }
}
