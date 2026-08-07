<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrent-refund idempotency.
 *
 * Spawns 10 OS-level parallel processes all calling ProcessRefund with the same
 * stripe_refund_id. Only one must win; the other nine must converge to the same
 * refund ID without creating duplicate rows.
 *
 * Does NOT use RefreshDatabase — parallel processes commit on their own connections
 * and those commits must remain visible after the test. Data is cleaned up in tearDown.
 */
class ConcurrentRefundTest extends TestCase
{
    private ?int $settlementId = null;

    protected function tearDown(): void
    {
        if ($this->settlementId !== null) {
            // Delete in FK-safe order (children first)
            $lineIds = DB::table('settlement_lines')
                ->where('settlement_id', $this->settlementId)
                ->pluck('id');

            $refundIds = DB::table('refunds')
                ->where('settlement_id', $this->settlementId)
                ->pluck('id');

            $refundLineIds = DB::table('refund_lines')
                ->whereIn('refund_id', $refundIds)
                ->pluck('id');

            DB::table('transfer_reversal_orders')
                ->whereIn('refund_line_id', $refundLineIds)
                ->delete();

            DB::table('ledger_entries')
                ->where('settlement_id', $this->settlementId)
                ->delete();

            DB::table('refund_lines')->whereIn('id', $refundLineIds)->delete();
            DB::table('refunds')->whereIn('id', $refundIds)->delete();
            DB::table('transfer_outbox')->whereIn('settlement_line_id', $lineIds)->delete();
            DB::table('settlement_lines')->whereIn('id', $lineIds)->delete();
            DB::table('settlements')->where('id', $this->settlementId)->delete();
        }

        parent::tearDown();
    }

    public function test_ten_concurrent_refunds_create_exactly_one_record(): void
    {
        $pi  = 'pi_ref_conc_' . uniqid();
        $rid = 're_ref_conc_' . uniqid();

        // ── Create committed settlement + 3 pending lines ─────────────
        $sid = (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $pi,
            'stripe_event_id'          => 'evt_rconc_' . uniqid(),
            'gross_cents'              => 10000, 'currency' => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps' => 4500, 'fund_bps' => 4500, 'ops_bps' => 1000,
            'artist_cents' => 4500, 'fund_cents' => 4500, 'ops_cents' => 1000,
            'status'    => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->settlementId = $sid;

        foreach ([['artist', 4500], ['fund', 4500], ['ops', 1000]] as [$type, $cents]) {
            DB::table('settlement_lines')->insertGetId([
                'settlement_id'  => $sid,
                'recipient_type' => $type,
                'amount_cents'   => $cents, 'currency' => 'EUR', 'weight' => 1,
                'status'         => 'pending',
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        }

        // ── Spawn 10 parallel workers ──────────────────────────────────
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = [];
            $procs[$i] = proc_open(
                PHP_BINARY . ' ' . base_path('artisan') . " refund:create-test {$rid} {$pi} 10000",
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $p,
                base_path(),
                ['APP_ENV' => 'testing']
            );
            $pipes[$i] = $p;
        }

        $ids = [];
        foreach ($procs as $i => $proc) {
            $stdout = trim((string) stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($proc);
            if ($stdout !== '' && (int) $stdout > 0) {
                $ids[] = (int) $stdout;
            }
        }

        // ── All workers must return the same refund ID ─────────────────
        $this->assertNotEmpty($ids, 'At least one worker must succeed');
        $uniqueIds = array_unique($ids);
        $this->assertCount(1, $uniqueIds, 'All workers must converge to the same refund ID');

        $refundId = $uniqueIds[0];

        // ── Exactly 1 refund row ───────────────────────────────────────
        $this->assertSame(
            1,
            (int) DB::table('refunds')->where('stripe_refund_id', $rid)->count(),
            'Exactly 1 refund row'
        );

        // ── Exactly 3 refund_lines (one per settlement_line) ──────────
        $this->assertSame(
            3,
            (int) DB::table('refund_lines')->where('refund_id', $refundId)->count(),
            'Exactly 3 refund_lines'
        );

        // ── Exactly 3 ledger debits (one per refund_line) ─────────────
        $refundLineIds = DB::table('refund_lines')
            ->where('refund_id', $refundId)
            ->pluck('id');

        $this->assertSame(
            3,
            (int) DB::table('ledger_entries')
                ->where('type', 'debit')
                ->whereIn('refund_line_id', $refundLineIds)
                ->count(),
            'Exactly 3 ledger debits'
        );

        // ── No duplicate idempotency keys ──────────────────────────────
        $keys = DB::table('ledger_entries')
            ->where('type', 'debit')
            ->whereIn('refund_line_id', $refundLineIds)
            ->pluck('idempotency_key');

        $this->assertSame($keys->count(), $keys->unique()->count(), 'No duplicate idempotency keys');
    }
}
