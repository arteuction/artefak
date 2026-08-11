<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchStripeTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\TestCase;

class DispatchStripeTransferTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────

    private function insertSettlement(string $pi = 'pi_test'): int
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
            'status'                   => 'pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function insertLine(int $settlementId, string $type = 'artist'): int
    {
        return (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'  => $settlementId,
            'recipient_type' => $type,
            'amount_cents'   => 4500,
            'currency'       => 'EUR',
            'weight'         => 1,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function insertOutbox(int $lineId, string $idemKey = 'idem_test'): int
    {
        return (int) DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $lineId,
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500,
            'currency'               => 'EUR',
            'stripe_idempotency_key' => $idemKey,
            'status'                 => 'pending',
            'attempt'                => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    private function makeStripeSuccess(string $transferId = 'tr_success'): StripeClient
    {
        $transfer = \Stripe\Transfer::constructFrom(['id' => $transferId]);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('create')->willReturn($transfer);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);

        return $stripe;
    }

    private function makeStripeFailure(\Exception $e): StripeClient
    {
        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('create')->willThrowException($e);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);

        return $stripe;
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_success_marks_outbox_dispatched(): void
    {
        $sid     = $this->insertSettlement('pi_ok1');
        $lid     = $this->insertLine($sid);
        $oid     = $this->insertOutbox($lid, 'idem_ok1');
        $stripe  = $this->makeStripeSuccess('tr_ok1');

        $job = new DispatchStripeTransfer($oid);
        $job->handle($stripe);

        $row = DB::table('transfer_outbox')->find($oid);
        $this->assertSame('dispatched', $row->status);
        $this->assertSame('tr_ok1',     $row->stripe_transfer_id);
        $this->assertNotNull($row->dispatched_at);
    }

    public function test_success_fills_settlement_line_transfer_id(): void
    {
        $sid    = $this->insertSettlement('pi_ok2');
        $lid    = $this->insertLine($sid);
        $oid    = $this->insertOutbox($lid, 'idem_ok2');
        $stripe = $this->makeStripeSuccess('tr_ok2');

        (new DispatchStripeTransfer($oid))->handle($stripe);

        $line = DB::table('settlement_lines')->find($lid);
        $this->assertSame('tr_ok2',       $line->stripe_transfer_id);
        $this->assertSame('transferred',  $line->status);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_already_dispatched_row_is_skipped(): void
    {
        $sid = $this->insertSettlement('pi_skip');
        $lid = $this->insertLine($sid);
        $oid = $this->insertOutbox($lid, 'idem_skip');

        // Mark as already dispatched
        DB::table('transfer_outbox')->where('id', $oid)->update(['status' => 'dispatched']);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->expects($this->never())->method('create');
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);

        (new DispatchStripeTransfer($oid))->handle($stripe);

        // Status unchanged
        $this->assertSame('dispatched', DB::table('transfer_outbox')->find($oid)->status);
    }

    // ── Retry / backoff ───────────────────────────────────────────

    public function test_stripe_error_increments_attempt_and_schedules_retry(): void
    {
        Queue::fake();

        $sid    = $this->insertSettlement('pi_err1');
        $lid    = $this->insertLine($sid);
        $oid    = $this->insertOutbox($lid, 'idem_err1');
        $stripe = $this->makeStripeFailure(
            new ApiConnectionException('Network error')
        );

        (new DispatchStripeTransfer($oid))->handle($stripe);

        $row = DB::table('transfer_outbox')->find($oid);
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempt);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertNotNull($row->last_error);

        // A re-queued job should have been dispatched
        Queue::assertPushed(DispatchStripeTransfer::class, fn ($j) => $j->outboxId === $oid);
    }

    public function test_final_attempt_marks_failed_and_does_not_requeue(): void
    {
        Queue::fake();

        $sid = $this->insertSettlement('pi_dead');
        $lid = $this->insertLine($sid);
        $oid = $this->insertOutbox($lid, 'idem_dead');

        // Simulate 4 previous attempts — next one is the 5th = final
        DB::table('transfer_outbox')->where('id', $oid)->update(['attempt' => 4]);

        $stripe = $this->makeStripeFailure(new ApiConnectionException('still failing'));

        (new DispatchStripeTransfer($oid))->handle($stripe);

        $row = DB::table('transfer_outbox')->find($oid);
        $this->assertSame('failed', $row->status);
        $this->assertSame(5, (int) $row->attempt);
        $this->assertNull($row->next_attempt_at);

        Queue::assertNotPushed(DispatchStripeTransfer::class);
    }

    public function test_missing_outbox_row_does_not_throw(): void
    {
        $stripe = $this->makeStripeSuccess();
        $job    = new DispatchStripeTransfer(999999);

        // Should return cleanly, not throw
        $job->handle($stripe);
        $this->assertTrue(true);
    }
}
