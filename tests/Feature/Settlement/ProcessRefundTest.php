<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Domain\Settlement\ProcessRefund;
use App\Jobs\ReverseStripeTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessRefundTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────

    private function insertSettlement(string $pi = 'pi_refund_test', string $status = 'pending'): int
    {
        return (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $pi,
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 4500,
            'fund_cents'               => 4500,
            'ops_cents'                => 1000,
            'status'                   => $status,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function insertLine(int $sid, string $type = 'artist', string $lineStatus = 'pending', ?string $transferId = null): int
    {
        return (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'      => $sid,
            'recipient_type'     => $type,
            'amount_cents'       => 4500,
            'currency'           => 'EUR',
            'weight'             => 1,
            'status'             => $lineStatus,
            'stripe_transfer_id' => $transferId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function insertOutbox(int $lineId, string $outboxStatus = 'pending'): int
    {
        return (int) DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $lineId,
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500,
            'currency'               => 'EUR',
            'stripe_idempotency_key' => 'idem_' . uniqid(),
            'status'                 => $outboxStatus,
            'attempt'                => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    // ── Happy path: settlement with pending outbox (not yet transferred) ──

    public function test_marks_settlement_as_refunded(): void
    {
        $sid = $this->insertSettlement('pi_r1');
        $lid = $this->insertLine($sid, 'artist');
        $this->insertOutbox($lid, 'pending');

        Queue::fake();
        (new ProcessRefund())->execute($sid, 're_001');

        $this->assertSame('refunded', DB::table('settlements')->find($sid)->status);
    }

    public function test_cancels_pending_outbox_rows(): void
    {
        $sid  = $this->insertSettlement('pi_r2');
        $lid1 = $this->insertLine($sid, 'artist');
        $lid2 = $this->insertLine($sid, 'fund');
        $oid1 = $this->insertOutbox($lid1, 'pending');
        $oid2 = $this->insertOutbox($lid2, 'pending');

        Queue::fake();
        (new ProcessRefund())->execute($sid, 're_002');

        $this->assertSame('failed', DB::table('transfer_outbox')->find($oid1)->status);
        $this->assertSame('failed', DB::table('transfer_outbox')->find($oid2)->status);
        $this->assertStringContainsString('Cancelled', DB::table('transfer_outbox')->find($oid1)->last_error);
    }

    public function test_does_not_cancel_dispatched_outbox_rows(): void
    {
        $sid = $this->insertSettlement('pi_r3');
        $lid = $this->insertLine($sid, 'artist', 'transferred', 'tr_already');
        $oid = $this->insertOutbox($lid, 'dispatched');

        Queue::fake();
        (new ProcessRefund())->execute($sid, 're_003');

        // Dispatched outbox row must remain dispatched (reversal job handles the Stripe side)
        $this->assertSame('dispatched', DB::table('transfer_outbox')->find($oid)->status);
    }

    // ── Reversal job dispatch ─────────────────────────────────────

    public function test_dispatches_reversal_job_for_each_transferred_line(): void
    {
        Queue::fake();

        $sid  = $this->insertSettlement('pi_r4');
        $lid1 = $this->insertLine($sid, 'artist', 'transferred', 'tr_artist');
        $lid2 = $this->insertLine($sid, 'fund',   'transferred', 'tr_fund');
        $lid3 = $this->insertLine($sid, 'ops',    'transferred', 'tr_ops');

        (new ProcessRefund())->execute($sid, 're_004');

        Queue::assertPushed(ReverseStripeTransfer::class, 3);
        Queue::assertPushed(
            ReverseStripeTransfer::class,
            fn ($j) => $j->lineId === $lid1 && $j->stripeRefundId === 're_004',
        );
    }

    public function test_does_not_dispatch_reversal_for_pending_lines(): void
    {
        Queue::fake();

        $sid = $this->insertSettlement('pi_r5');
        $lid = $this->insertLine($sid, 'artist', 'pending');   // never transferred
        $this->insertOutbox($lid, 'pending');

        (new ProcessRefund())->execute($sid, 're_005');

        Queue::assertNotPushed(ReverseStripeTransfer::class);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_already_refunded_settlement_returns_false(): void
    {
        Queue::fake();

        $sid = $this->insertSettlement('pi_r6', 'refunded');

        $result = (new ProcessRefund())->execute($sid, 're_006');

        $this->assertFalse($result);
        Queue::assertNotPushed(ReverseStripeTransfer::class);
    }

    public function test_second_refund_call_does_not_double_cancel_outbox(): void
    {
        Queue::fake();

        $sid = $this->insertSettlement('pi_r7');
        $lid = $this->insertLine($sid, 'artist');
        $this->insertOutbox($lid, 'pending');

        (new ProcessRefund())->execute($sid, 're_007');
        (new ProcessRefund())->execute($sid, 're_007b'); // second call

        // Still exactly 1 row, still failed
        $count = DB::table('transfer_outbox')->where('settlement_line_id', $lid)->count();
        $this->assertSame(1, $count);
    }

    // ── Non-existent settlement ───────────────────────────────────

    public function test_unknown_settlement_throws(): void
    {
        $this->expectException(\DomainException::class);
        (new ProcessRefund())->execute(999999, 're_999');
    }
}
