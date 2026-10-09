<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Financial\RunReconciliation;
use App\Models\ReconciliationMismatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 58: Financial reconciliation sub-checks.
 *
 * - Per-type checks: payments, refunds, transfers
 * - Durable mismatch records with Stripe transaction IDs
 * - Bugfix: transfer_outbox status 'completed' → 'dispatched'
 */
final class Phase58ApiTest extends TestCase
{
    use RefreshDatabase;

    private function periodStart(): \Carbon\Carbon { return \Carbon\Carbon::parse('2026-01-01'); }
    private function periodEnd(): \Carbon\Carbon   { return \Carbon\Carbon::parse('2026-01-01'); }

    // ── Totals still work without sub-checks ─────────────────────────────────

    public function test_reconciliation_matches_with_no_sub_checks(): void
    {
        $this->makeSettlement('pi_match_1', 10_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    10_000,
            stripeTransferredCents: 0,
        );

        $this->assertSame('matched', $run->status);
        $this->assertSame(0, $run->mismatch_count);
    }

    // ── Payment sub-check ─────────────────────────────────────────────────────

    public function test_payment_subcheck_records_missing_stripe_charge(): void
    {
        // Internal settlement exists, Stripe charge map is empty
        $this->makeSettlement('pi_orphan', 5_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    0,
            stripeTransferredCents: 0,
            stripeCharges:          [], // no matching charge
        );

        $this->assertSame('mismatched', $run->status);
        $this->assertSame(1, $run->mismatch_count);

        $mismatch = ReconciliationMismatch::where('reconciliation_run_id', $run->id)->first();
        $this->assertNotNull($mismatch);
        $this->assertSame('payment', $mismatch->check_type);
        $this->assertSame('pi_orphan', $mismatch->internal_reference);
        $this->assertSame('open', $mismatch->resolution_status);
    }

    public function test_payment_subcheck_records_unknown_stripe_charge(): void
    {
        // Stripe has a charge with no internal settlement
        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    7_500,
            stripeTransferredCents: 0,
            stripeCharges:          ['pi_unknown' => 7_500],
        );

        $this->assertSame('mismatched', $run->status);

        $mismatch = ReconciliationMismatch::where('reconciliation_run_id', $run->id)
            ->where('check_type', 'payment')
            ->first();

        $this->assertNotNull($mismatch);
        $this->assertSame('pi_unknown', $mismatch->stripe_transaction_id);
        $this->assertSame(7_500, (int) $mismatch->delta_cents);
    }

    public function test_payment_subcheck_records_amount_mismatch(): void
    {
        $this->makeSettlement('pi_amt_mismatch', 10_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    9_000, // wrong total
            stripeTransferredCents: 0,
            stripeCharges:          ['pi_amt_mismatch' => 9_000], // 1000 delta
        );

        $this->assertSame('mismatched', $run->status);
        $mismatch = ReconciliationMismatch::where('reconciliation_run_id', $run->id)->first();
        $this->assertSame(1_000, (int) $mismatch->delta_cents); // 10000 - 9000
    }

    public function test_payment_subcheck_passes_when_all_match(): void
    {
        $this->makeSettlement('pi_ok_1', 5_000);
        $this->makeSettlement('pi_ok_2', 3_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    8_000,
            stripeTransferredCents: 0,
            stripeCharges:          ['pi_ok_1' => 5_000, 'pi_ok_2' => 3_000],
        );

        $this->assertSame('matched', $run->status);
        $this->assertSame(0, $run->mismatch_count);
        $this->assertSame(0, ReconciliationMismatch::count());
    }

    // ── Transfer sub-check ────────────────────────────────────────────────────

    public function test_transfer_subcheck_records_missing_stripe_transfer(): void
    {
        $this->makeDispatchedTransfer('tr_internal_only', 2_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    0,
            stripeTransferredCents: 0,
            stripeTransfers:        [], // not found on Stripe
        );

        $this->assertSame('mismatched', $run->status);
        $mismatch = ReconciliationMismatch::where('check_type', 'transfer')->first();
        $this->assertNotNull($mismatch);
        $this->assertSame('tr_internal_only', $mismatch->internal_reference);
    }

    public function test_transfer_subcheck_passes_when_all_match(): void
    {
        $this->makeDispatchedTransfer('tr_match_1', 1_500);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    0,
            stripeTransferredCents: 1_500,
            stripeTransfers:        ['tr_match_1' => 1_500],
        );

        $this->assertSame('matched', $run->status);
        $this->assertSame(0, ReconciliationMismatch::count());
    }

    // ── Refund sub-check ─────────────────────────────────────────────────────

    public function test_refund_subcheck_records_missing_stripe_refund(): void
    {
        // Use updated_at within the reconciliation period for the refund query
        DB::table('settlements')->insert([
            'stripe_payment_intent_id' => 'pi_refund_orphan',
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 4_000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 1800,
            'fund_cents'               => 1800,
            'ops_cents'                => 400,
            'status'                   => 'refunded',
            'created_at'               => '2026-01-01 12:00:00',
            'updated_at'               => '2026-01-01 12:00:00',
        ]);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    0,
            stripeTransferredCents: 0,
            stripeRefunds:          [], // no Stripe refund found — triggers mismatch
        );

        $this->assertSame('mismatched', $run->status);
        $mismatch = ReconciliationMismatch::where('check_type', 'refund')->first();
        $this->assertNotNull($mismatch);
        $this->assertSame('pi_refund_orphan', $mismatch->internal_reference);
    }

    // ── Mismatch model ────────────────────────────────────────────────────────

    public function test_mismatch_default_resolution_status_is_open(): void
    {
        $this->makeSettlement('pi_mismatch_status', 3_000);

        $run = (new RunReconciliation())->execute(
            periodStart:            $this->periodStart(),
            periodEnd:              $this->periodEnd(),
            stripeReceivedCents:    0,
            stripeTransferredCents: 0,
            stripeCharges:          [],
        );

        $mismatches = ReconciliationMismatch::where('reconciliation_run_id', $run->id)->get();
        $this->assertTrue($mismatches->every(fn ($m) => $m->resolution_status === 'open'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSettlement(string $piId, int $grossCents, string $status = 'completed'): void
    {
        DB::table('settlements')->insert([
            'stripe_payment_intent_id' => $piId,
            'stripe_event_id'          => 'evt_test_' . uniqid(),
            'gross_cents'              => $grossCents,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => (int) ($grossCents * 0.45),
            'fund_cents'               => (int) ($grossCents * 0.45),
            'ops_cents'                => (int) ($grossCents * 0.10),
            'status'                   => $status,
            'created_at'               => '2026-01-01 12:00:00',
            'updated_at'               => '2026-01-01 12:00:00',
        ]);
    }

    private function makeDispatchedTransfer(string $stripeTransferId, int $amountCents): void
    {
        // Need a settlement_line_id for the FK — create a minimal settlement + line
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_for_transfer_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => $amountCents * 2,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => $amountCents,
            'fund_cents'               => $amountCents,
            'ops_cents'                => 0,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id'   => $settlementId,
            'recipient_type'  => 'artist',
            'amount_cents'    => $amountCents,
            'currency'        => 'EUR',
            'entity_name'     => 'Test Artist',
            'entity_role'     => 'artist',
            'legal_entity_id' => null,
            'weight'          => 4500,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('transfer_outbox')->insert([
            'settlement_line_id'      => $lineId,
            'stripe_account_id'       => 'acct_test',
            'amount_cents'            => $amountCents,
            'currency'                => 'EUR',
            'stripe_idempotency_key'  => 'idem_' . uniqid(),
            'status'                  => 'dispatched',
            'stripe_transfer_id'      => $stripeTransferId,
            'dispatched_at'           => '2026-01-01 14:00:00',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }
}
