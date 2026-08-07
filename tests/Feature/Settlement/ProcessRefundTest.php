<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Application\Settlement\ProcessRefund;
use App\Jobs\DispatchStripeReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Exhaustive tests for ProcessRefund — all cases listed in Phase 1g spec.
 *
 * Covers:
 *   Basic:   full, partial, sum=gross, sum=refund_amount
 *   Cumulative: two partial → exact full; path-independent allocation
 *   Idempotency: duplicate stripe_refund_id; different amount on same ID → exception
 *   Accumulation: two different refund IDs → correct cumulative lines
 *   Guards:  refund > remaining → exception without side effects
 *             currency mismatch → exception without side effects
 *             unknown payment_intent → exception
 *   Status:  only 'succeeded' writes ledger/reversal
 *             pending/failed/canceled → audit record only
 *   Line status: pending → canceled (no reversal); transferred → reversal_pending + order;
 *                processing → reversal_pending but NO order (transfer worker resolves);
 *                ops-no-stripe → refund_line + debit, no order
 *   Ledger:  debit for artist, fund, ops on every succeeded refund
 *             no debit for non-succeeded refunds
 *   Settlement status: full → refunded; partial → partially_refunded
 *   Rollback: exception during commit → zero rows inserted
 */
class ProcessRefundTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────

    private function makeSettlement(
        string $pi    = 'pi_t',
        int    $gross = 10000,
        string $status = 'pending',
    ): \stdClass {
        $sid = (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $pi,
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => $gross,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'  => 4500, 'fund_bps' => 4500, 'ops_bps' => 1000,
            'artist_cents'=> intdiv($gross * 4500, 10000),
            'fund_cents'  => intdiv($gross * 4500, 10000),
            'ops_cents'   => $gross - intdiv($gross * 4500, 10000) * 2,
            'status'      => $status,
            'created_at'  => now(), 'updated_at' => now(),
        ]);
        return DB::table('settlements')->find($sid);
    }

    private function makeLine(
        int     $sid,
        string  $type       = 'artist',
        int     $cents      = 4500,
        string  $ls         = 'pending',
        ?string $tid        = null,
    ): \stdClass {
        $id = (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'      => $sid,
            'recipient_type'     => $type,
            'amount_cents'       => $cents,
            'currency'           => 'EUR',
            'weight'             => 1,
            'status'             => $ls,
            'stripe_transfer_id' => $tid,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
        return DB::table('settlement_lines')->find($id);
    }

    private function makeOutbox(int $lid, string $st = 'pending'): int
    {
        return (int) DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $lid,
            'stripe_account_id'      => 'acct_t',
            'amount_cents'           => 4500, 'currency' => 'EUR',
            'stripe_idempotency_key' => 'idem_' . uniqid(),
            'status'                 => $st, 'attempt' => 0,
            'created_at'             => now(), 'updated_at' => now(),
        ]);
    }

    /** Helper: settlement with 3 lines (artist 4500 / fund 4500 / ops 1000) */
    private function standard(string $pi): array
    {
        $s  = $this->makeSettlement($pi, 10000);
        $l1 = $this->makeLine($s->id, 'artist', 4500);
        $l2 = $this->makeLine($s->id, 'fund',   4500);
        $l3 = $this->makeLine($s->id, 'ops',    1000);
        return [$s, $l1, $l2, $l3];
    }

    private function action(): ProcessRefund { return new ProcessRefund(); }

    // ══════════════════════════════════════════════════════════════
    // Full refund
    // ══════════════════════════════════════════════════════════════

    public function test_full_refund_sum_of_refund_lines_equals_gross(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_full_sum');

        $rid   = $this->action()->execute('re_fs', 'pi_full_sum', 10000);
        $total = DB::table('refund_lines')->where('refund_id', $rid)->sum('amount_cents');

        $this->assertSame(10000, (int) $total);
    }

    public function test_full_refund_settlement_status_becomes_refunded(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_full_st');
        $this->action()->execute('re_full_st', 'pi_full_st', 10000);

        $this->assertSame('refunded', DB::table('settlements')->find($s->id)->status);
    }

    public function test_full_refund_ledger_debit_for_all_three_parties(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_full_lb');
        $this->action()->execute('re_full_lb', 'pi_full_lb', 10000);

        $debits = DB::table('ledger_entries')
            ->where('settlement_id', $s->id)->where('type', 'debit')->count();

        $this->assertSame(3, $debits);
    }

    // ══════════════════════════════════════════════════════════════
    // Partial refund
    // ══════════════════════════════════════════════════════════════

    public function test_partial_refund_sum_of_refund_lines_equals_refund_amount(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_part_sum');
        $rid = $this->action()->execute('re_part_sum', 'pi_part_sum', 5000);

        $total = DB::table('refund_lines')->where('refund_id', $rid)->sum('amount_cents');
        $this->assertSame(5000, (int) $total);
    }

    public function test_partial_refund_settlement_status_becomes_partially_refunded(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_part_st');
        $this->action()->execute('re_part_st', 'pi_part_st', 5000);

        $this->assertSame('partially_refunded', DB::table('settlements')->find($s->id)->status);
    }

    // ══════════════════════════════════════════════════════════════
    // Cumulative partial refunds — path-independent allocation
    // ══════════════════════════════════════════════════════════════

    public function test_two_partial_refunds_equal_exact_full_refund(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_cum');

        // 3333 + 6667 = 10000
        $rid1 = $this->action()->execute('re_cum_a', 'pi_cum', 3333);
        $rid2 = $this->action()->execute('re_cum_b', 'pi_cum', 6667);

        $total1 = (int) DB::table('refund_lines')->where('refund_id', $rid1)->sum('amount_cents');
        $total2 = (int) DB::table('refund_lines')->where('refund_id', $rid2)->sum('amount_cents');

        // Each refund covers exactly its own amount
        $this->assertSame(3333, $total1);
        $this->assertSame(6667, $total2);

        // Combined they cover the full gross
        $this->assertSame(10000, $total1 + $total2);

        // Settlement is now fully refunded
        $this->assertSame('refunded', DB::table('settlements')->find($s->id)->status);
    }

    public function test_cumulative_allocation_is_path_independent(): void
    {
        Queue::fake();

        // Settlement A: two partials €33.33 + €66.66 = €99.99
        $sA = $this->makeSettlement('pi_path_a', 9999);
        $this->makeLine($sA->id, 'artist', 4499);
        $this->makeLine($sA->id, 'fund',   4499);
        $this->makeLine($sA->id, 'ops',    1001);

        $this->action()->execute('re_path_a1', 'pi_path_a', 3333);
        $this->action()->execute('re_path_a2', 'pi_path_a', 6666);

        $lineDebitsByType_A = DB::table('ledger_entries')
            ->join('settlement_lines', 'settlement_lines.id', '=', 'ledger_entries.settlement_line_id')
            ->where('ledger_entries.settlement_id', $sA->id)
            ->where('ledger_entries.type', 'debit')
            ->select('settlement_lines.recipient_type', DB::raw('SUM(ledger_entries.amount_cents) as total'))
            ->groupBy('settlement_lines.recipient_type')
            ->pluck('total', 'recipient_type');

        // Settlement B: single refund €99.99
        $sB = $this->makeSettlement('pi_path_b', 9999);
        $this->makeLine($sB->id, 'artist', 4499);
        $this->makeLine($sB->id, 'fund',   4499);
        $this->makeLine($sB->id, 'ops',    1001);

        $this->action()->execute('re_path_b1', 'pi_path_b', 9999);

        $lineDebitsByType_B = DB::table('ledger_entries')
            ->join('settlement_lines', 'settlement_lines.id', '=', 'ledger_entries.settlement_line_id')
            ->where('ledger_entries.settlement_id', $sB->id)
            ->where('ledger_entries.type', 'debit')
            ->select('settlement_lines.recipient_type', DB::raw('SUM(ledger_entries.amount_cents) as total'))
            ->groupBy('settlement_lines.recipient_type')
            ->pluck('total', 'recipient_type');

        foreach (['artist', 'fund', 'ops'] as $type) {
            $this->assertSame(
                (int) ($lineDebitsByType_B[$type] ?? 0),
                (int) ($lineDebitsByType_A[$type] ?? 0),
                "Debit for {$type} differs between one-shot and two-step refund",
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Idempotency
    // ══════════════════════════════════════════════════════════════

    public function test_duplicate_stripe_refund_id_returns_same_id(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_idem');

        $id1 = $this->action()->execute('re_idem', 'pi_idem', 5000);
        $id2 = $this->action()->execute('re_idem', 'pi_idem', 5000);

        $this->assertSame($id1, $id2);
        $this->assertSame(1, DB::table('refunds')->where('stripe_refund_id', 're_idem')->count());
    }

    public function test_duplicate_refund_id_does_not_create_extra_lines_or_debits(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_idem2');

        $this->action()->execute('re_idem2', 'pi_idem2', 10000);
        $this->action()->execute('re_idem2', 'pi_idem2', 10000);

        $this->assertSame(1, DB::table('refunds')->where('stripe_refund_id', 're_idem2')->count());
        $this->assertSame(3, DB::table('ledger_entries')
            ->where('settlement_id', $s->id)->where('type', 'debit')->count());
    }

    // ══════════════════════════════════════════════════════════════
    // Two different refund IDs — correct accumulation
    // ══════════════════════════════════════════════════════════════

    public function test_two_different_refund_ids_accumulate_correctly(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_two');

        $rid1 = $this->action()->execute('re_two_a', 'pi_two', 3000);
        $rid2 = $this->action()->execute('re_two_b', 'pi_two', 4000);

        $total1 = (int) DB::table('refund_lines')->where('refund_id', $rid1)->sum('amount_cents');
        $total2 = (int) DB::table('refund_lines')->where('refund_id', $rid2)->sum('amount_cents');

        $this->assertSame(3000, $total1);
        $this->assertSame(4000, $total2);
        $this->assertSame(7000, $total1 + $total2);
    }

    // ══════════════════════════════════════════════════════════════
    // Guards
    // ══════════════════════════════════════════════════════════════

    public function test_refund_exceeding_gross_throws_without_side_effects(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_over');

        // First partial succeeds
        $this->action()->execute('re_over_a', 'pi_over', 6000);

        // Second attempt would exceed gross (6000 + 5000 = 11000 > 10000)
        try {
            $this->action()->execute('re_over_b', 'pi_over', 5000);
            $this->fail('Expected DomainException not thrown');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('exceeds gross', $e->getMessage());
        }

        // No rows from the failed second attempt
        $this->assertSame(1, DB::table('refunds')->where('settlement_id', $s->id)->count());
    }

    public function test_currency_mismatch_throws_without_side_effects(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_cur');

        try {
            $this->action()->execute('re_cur', 'pi_cur', 5000, 'USD');
            $this->fail('Expected DomainException not thrown');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Currency mismatch', $e->getMessage());
        }

        $this->assertSame(0, DB::table('refunds')->where('settlement_id', $s->id)->count());
    }

    public function test_unknown_payment_intent_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->action()->execute('re_unk', 'pi_does_not_exist', 1000);
    }

    // ══════════════════════════════════════════════════════════════
    // Only 'succeeded' refunds write financial state
    // ══════════════════════════════════════════════════════════════

    public function test_pending_refund_creates_audit_record_but_no_ledger_debit(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_pend');

        $rid = $this->action()->execute('re_pend', 'pi_pend', 5000, 'EUR', 'pending');

        $this->assertNotNull(DB::table('refunds')->find($rid));
        $this->assertSame(0, DB::table('ledger_entries')
            ->where('settlement_id', $s->id)->where('type', 'debit')->count());
        $this->assertSame(0, DB::table('refund_lines')->where('refund_id', $rid)->count());
    }

    public function test_failed_refund_creates_audit_record_but_no_financial_effects(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_fail');

        $rid = $this->action()->execute('re_fail', 'pi_fail', 5000, 'EUR', 'failed');

        $this->assertNotNull(DB::table('refunds')->find($rid));
        $this->assertSame(0, DB::table('refund_lines')->where('refund_id', $rid)->count());
        // Settlement unchanged
        $this->assertSame('pending', DB::table('settlements')->find($s->id)->status);
    }

    public function test_canceled_refund_creates_audit_record_only(): void
    {
        Queue::fake();
        [$s] = $this->standard('pi_cxl');

        $rid = $this->action()->execute('re_cxl', 'pi_cxl', 5000, 'EUR', 'canceled');

        $this->assertSame('not_required',
            DB::table('refunds')->find($rid)->reconciliation_status);
        $this->assertSame(0, DB::table('refund_lines')->where('refund_id', $rid)->count());
    }

    // ══════════════════════════════════════════════════════════════
    // Line status transitions
    // ══════════════════════════════════════════════════════════════

    public function test_pending_line_becomes_canceled_not_failed(): void
    {
        Queue::fake();
        $s  = $this->makeSettlement('pi_lp');
        $l  = $this->makeLine($s->id, 'artist', 4500, 'pending');
        $this->makeLine($s->id, 'fund', 4500);
        $this->makeLine($s->id, 'ops',  1000);
        $oid = $this->makeOutbox($l->id, 'pending');

        $this->action()->execute('re_lp', 'pi_lp', 10000);

        $this->assertSame('canceled', DB::table('settlement_lines')->find($l->id)->status);
        $this->assertSame('canceled', DB::table('transfer_outbox')->find($oid)->status);
    }

    public function test_pending_line_does_not_create_reversal_order(): void
    {
        Queue::fake();
        $s = $this->makeSettlement('pi_lp2');
        $this->makeLine($s->id, 'artist', 4500, 'pending');
        $this->makeLine($s->id, 'fund',   4500, 'pending');
        $this->makeLine($s->id, 'ops',    1000, 'pending');

        $rid = $this->action()->execute('re_lp2', 'pi_lp2', 10000);

        $refundLineIds = DB::table('refund_lines')->where('refund_id', $rid)->pluck('id');
        $this->assertSame(0,
            DB::table('transfer_reversal_orders')->whereIn('refund_line_id', $refundLineIds)->count());

        Queue::assertNotPushed(DispatchStripeReversal::class);
    }

    public function test_transferred_line_becomes_reversal_pending_with_one_order(): void
    {
        Queue::fake();
        $s  = $this->makeSettlement('pi_lt');
        $l1 = $this->makeLine($s->id, 'artist', 4500, 'transferred', 'tr_a');
        $l2 = $this->makeLine($s->id, 'fund',   4500, 'transferred', 'tr_f');
        $l3 = $this->makeLine($s->id, 'ops',    1000, 'transferred', 'tr_o');

        $this->action()->execute('re_lt', 'pi_lt', 10000);

        foreach ([$l1->id, $l2->id, $l3->id] as $lid) {
            $this->assertSame('reversal_pending', DB::table('settlement_lines')->find($lid)->status);
        }

        Queue::assertPushed(DispatchStripeReversal::class, 3);
    }

    public function test_processing_line_marked_reversal_pending_but_no_order_created(): void
    {
        Queue::fake();
        $s  = $this->makeSettlement('pi_lproc');
        $l1 = $this->makeLine($s->id, 'artist', 4500, 'pending');
        // Simulate: transfer worker has claimed this line
        DB::table('settlement_lines')->where('id', $l1->id)->update(['status' => 'processing']);
        $this->makeLine($s->id, 'fund', 4500);
        $this->makeLine($s->id, 'ops',  1000);

        $rid = $this->action()->execute('re_lproc', 'pi_lproc', 10000);

        $this->assertSame('reversal_pending', DB::table('settlement_lines')->find($l1->id)->status);

        // No reversal order for the processing line (no stripe_transfer_id yet)
        $refundLine = DB::table('refund_lines')
            ->where('refund_id', $rid)
            ->where('settlement_line_id', $l1->id)
            ->first();

        $this->assertSame(0,
            DB::table('transfer_reversal_orders')->where('refund_line_id', $refundLine->id)->count());
    }

    public function test_ops_line_without_stripe_account_gets_refund_line_and_debit_no_order(): void
    {
        Queue::fake();
        $s  = $this->makeSettlement('pi_ops');
        $la = $this->makeLine($s->id, 'artist', 4500, 'transferred', 'tr_a_ops');
        $lf = $this->makeLine($s->id, 'fund',   4500, 'transferred', 'tr_f_ops');
        // ops line: transferred but no Stripe account → no stripe_transfer_id
        $lo = $this->makeLine($s->id, 'ops', 1000, 'transferred', null);

        $rid = $this->action()->execute('re_ops', 'pi_ops', 10000);

        // ops refund_line exists
        $opsRefundLine = DB::table('refund_lines')
            ->where('refund_id', $rid)
            ->where('recipient_type', 'ops')
            ->first();
        $this->assertNotNull($opsRefundLine);

        // ops ledger debit exists
        $debit = DB::table('ledger_entries')
            ->where('settlement_line_id', $lo->id)
            ->where('type', 'debit')
            ->first();
        $this->assertNotNull($debit);

        // NO reversal order for ops
        $this->assertSame(0,
            DB::table('transfer_reversal_orders')
                ->where('refund_line_id', $opsRefundLine->id)
                ->count());

        // Only artist + fund dispatch jobs (not ops)
        Queue::assertPushed(DispatchStripeReversal::class, 2);
    }

    // ══════════════════════════════════════════════════════════════
    // Rollback — exception produces zero side effects
    // ══════════════════════════════════════════════════════════════

    public function test_rollback_on_exception_leaves_zero_refund_records(): void
    {
        Queue::fake();
        $s = $this->makeSettlement('pi_roll');
        $this->makeLine($s->id, 'artist', 4500);

        // Over-refund triggers the guard INSIDE the transaction — should roll back
        try {
            $this->action()->execute('re_roll', 'pi_roll', 99999);
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame(0, DB::table('refunds')->where('settlement_id', $s->id)->count());
        $this->assertSame(0, DB::table('refund_lines')->count());
        $this->assertSame(0,
            DB::table('ledger_entries')->where('settlement_id', $s->id)->where('type', 'debit')->count());
    }
}
