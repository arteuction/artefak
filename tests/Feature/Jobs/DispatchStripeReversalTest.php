<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchStripeReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\TestCase;

class DispatchStripeReversalTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────

    private function fixtures(string $transferId = 'tr_orig'): array
    {
        $sid = (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_rev_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 10000, 'currency' => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500, 'fund_bps' => 4500, 'ops_bps' => 1000,
            'artist_cents'             => 4500, 'fund_cents' => 4500, 'ops_cents' => 1000,
            'status'                   => 'refunded',
            'created_at'               => now(), 'updated_at' => now(),
        ]);

        $lid = (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'      => $sid,
            'recipient_type'     => 'artist',
            'amount_cents'       => 4500, 'currency' => 'EUR', 'weight' => 1,
            'status'             => 'reversal_pending',
            'stripe_transfer_id' => $transferId,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        $refundId = (int) DB::table('refunds')->insertGetId([
            'stripe_refund_id'      => 're_' . uniqid(),
            'settlement_id'         => $sid,
            'amount_cents'          => 4500, 'currency' => 'EUR',
            'is_partial'            => false,
            'refund_status'         => 'succeeded',
            'reconciliation_status' => 'pending',
            'created_at'            => now(), 'updated_at' => now(),
        ]);

        $refundLineId = (int) DB::table('refund_lines')->insertGetId([
            'refund_id'          => $refundId,
            'settlement_line_id' => $lid,
            'amount_cents'       => 4500, 'currency' => 'EUR',
            'recipient_type'     => 'artist',
            'status'             => 'pending',
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        $orderId = (int) DB::table('transfer_reversal_orders')->insertGetId([
            'refund_line_id'     => $refundLineId,
            'stripe_transfer_id' => $transferId,
            'amount_cents'       => 4500, 'currency' => 'EUR',
            'idempotency_key'    => 'rev_idem_' . uniqid(),
            'status'             => 'pending', 'attempt' => 0,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        return compact('sid', 'lid', 'refundLineId', 'orderId');
    }

    private function makeStripeSuccess(string $reversalId = 'trr_ok'): StripeClient
    {
        $reversal  = \Stripe\TransferReversal::constructFrom(['id' => $reversalId]);
        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('createReversal')->willReturn($reversal);
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);
        return $stripe;
    }

    private function makeStripeFailure(): StripeClient
    {
        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('createReversal')->willThrowException(
            new ApiConnectionException('network error')
        );
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);
        return $stripe;
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_success_marks_order_reversed(): void
    {
        $f = $this->fixtures('tr_s1');
        (new DispatchStripeReversal($f['orderId']))->handle($this->makeStripeSuccess('trr_s1'));

        $o = DB::table('transfer_reversal_orders')->find($f['orderId']);
        $this->assertSame('reversed', $o->status);
        $this->assertSame('trr_s1',   $o->stripe_reversal_id);
        $this->assertNotNull($o->reversed_at);
        $this->assertNull($o->processing_started_at);
    }

    public function test_success_marks_refund_line_reversed(): void
    {
        $f = $this->fixtures('tr_s2');
        (new DispatchStripeReversal($f['orderId']))->handle($this->makeStripeSuccess());

        $this->assertSame('reversed', DB::table('refund_lines')->find($f['refundLineId'])->status);
    }

    public function test_success_marks_settlement_line_reversed(): void
    {
        $f = $this->fixtures('tr_s3');
        (new DispatchStripeReversal($f['orderId']))->handle($this->makeStripeSuccess());

        $this->assertSame('reversed', DB::table('settlement_lines')->find($f['lid'])->status);
    }

    // ── Lease / idempotency ───────────────────────────────────────

    public function test_non_pending_order_is_skipped(): void
    {
        $f = $this->fixtures('tr_skip');
        DB::table('transfer_reversal_orders')->where('id', $f['orderId'])->update(['status' => 'reversed']);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->expects($this->never())->method('createReversal');
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);

        (new DispatchStripeReversal($f['orderId']))->handle($stripe);
        $this->assertSame('reversed', DB::table('transfer_reversal_orders')->find($f['orderId'])->status);
    }

    // ── Retry / backoff ───────────────────────────────────────────

    public function test_stripe_error_resets_to_pending_with_backoff(): void
    {
        Queue::fake();
        $f = $this->fixtures('tr_err1');

        (new DispatchStripeReversal($f['orderId']))->handle($this->makeStripeFailure());

        $o = DB::table('transfer_reversal_orders')->find($f['orderId']);
        $this->assertSame('pending', $o->status);
        $this->assertSame(1, (int) $o->attempt);
        $this->assertNotNull($o->next_attempt_at);
        $this->assertNull($o->processing_started_at);

        Queue::assertPushed(DispatchStripeReversal::class, fn ($j) => $j->reversalOrderId === $f['orderId']);
    }

    public function test_final_attempt_marks_failed_no_requeue(): void
    {
        Queue::fake();
        $f = $this->fixtures('tr_dead');
        DB::table('transfer_reversal_orders')->where('id', $f['orderId'])->update(['attempt' => 4]);

        (new DispatchStripeReversal($f['orderId']))->handle($this->makeStripeFailure());

        $o = DB::table('transfer_reversal_orders')->find($f['orderId']);
        $this->assertSame('failed', $o->status);
        $this->assertSame(5, (int) $o->attempt);
        $this->assertNull($o->next_attempt_at);

        Queue::assertNotPushed(DispatchStripeReversal::class);
    }

    public function test_missing_order_does_not_throw(): void
    {
        (new DispatchStripeReversal(999999))->handle($this->makeStripeSuccess());
        $this->assertTrue(true);
    }

    // ── Stripe-success / DB-crash retry scenario ──────────────────

    public function test_already_reversed_order_skipped_on_retry(): void
    {
        // Simulate: Stripe succeeded, DB write succeeded, but job was
        // re-queued anyway (e.g., worker crash before ack).
        // On retry the order is 'reversed' → should skip cleanly.
        $f = $this->fixtures('tr_retry');
        DB::table('transfer_reversal_orders')->where('id', $f['orderId'])->update(['status' => 'reversed']);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->expects($this->never())->method('createReversal');
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('transfers')->willReturn($transfers);

        (new DispatchStripeReversal($f['orderId']))->handle($stripe);
        $this->assertTrue(true);
    }
}
