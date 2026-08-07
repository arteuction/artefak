<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ReverseStripeTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\TestCase;

class ReverseStripeTransferTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────

    private function insertSettlement(): int
    {
        return (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_rev_' . uniqid(),
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
            'status'                   => 'refunded',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function insertLine(int $sid, string $type = 'artist', string $transferId = 'tr_to_reverse'): int
    {
        return (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'      => $sid,
            'recipient_type'     => $type,
            'amount_cents'       => 4500,
            'currency'           => 'EUR',
            'weight'             => 1,
            'status'             => 'transferred',
            'stripe_transfer_id' => $transferId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function makeStripeSuccess(): StripeClient
    {
        $reversal     = \Stripe\TransferReversal::constructFrom(['id' => 'trr_success']);
        $transfers    = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('createReversal')->willReturn($reversal);
        $stripe            = $this->createMock(StripeClient::class);
        $stripe->transfers = $transfers;
        return $stripe;
    }

    private function makeStripeFailure(): StripeClient
    {
        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->method('createReversal')->willThrowException(
            new ApiConnectionException('network error')
        );
        $stripe            = $this->createMock(StripeClient::class);
        $stripe->transfers = $transfers;
        return $stripe;
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_success_marks_line_reversed(): void
    {
        $sid    = $this->insertSettlement();
        $lid    = $this->insertLine($sid, 'artist', 'tr_rev_ok');
        $stripe = $this->makeStripeSuccess();

        (new ReverseStripeTransfer($lid, 're_ok'))->handle($stripe);

        $this->assertSame('reversed', DB::table('settlement_lines')->find($lid)->status);
    }

    public function test_fund_reversal_inserts_ledger_debit(): void
    {
        $sid    = $this->insertSettlement();
        $lid    = $this->insertLine($sid, 'fund', 'tr_fund_rev');
        $stripe = $this->makeStripeSuccess();

        (new ReverseStripeTransfer($lid, 're_fund'))->handle($stripe);

        $debit = DB::table('ledger_entries')
            ->where('settlement_line_id', $lid)
            ->where('type', 'debit')
            ->first();

        $this->assertNotNull($debit);
        $this->assertSame(4500, (int) $debit->amount_cents);
        $this->assertSame('Refund reversal', $debit->note);
    }

    public function test_artist_reversal_does_not_insert_ledger_debit(): void
    {
        $sid    = $this->insertSettlement();
        $lid    = $this->insertLine($sid, 'artist', 'tr_artist_rev');
        $stripe = $this->makeStripeSuccess();

        (new ReverseStripeTransfer($lid, 're_artist'))->handle($stripe);

        $count = DB::table('ledger_entries')
            ->where('settlement_line_id', $lid)
            ->where('type', 'debit')
            ->count();

        $this->assertSame(0, $count);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_already_reversed_line_is_skipped(): void
    {
        $sid = $this->insertSettlement();
        $lid = $this->insertLine($sid, 'artist', 'tr_skip');
        DB::table('settlement_lines')->where('id', $lid)->update(['status' => 'reversed']);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->expects($this->never())->method('createReversal');
        $stripe            = $this->createMock(StripeClient::class);
        $stripe->transfers = $transfers;

        (new ReverseStripeTransfer($lid, 're_skip'))->handle($stripe);

        $this->assertSame('reversed', DB::table('settlement_lines')->find($lid)->status);
    }

    public function test_line_without_transfer_id_is_skipped_gracefully(): void
    {
        $sid = $this->insertSettlement();
        $lid = (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'  => $sid,
            'recipient_type' => 'ops',
            'amount_cents'   => 1000,
            'currency'       => 'EUR',
            'weight'         => 1,
            'status'         => 'pending', // never dispatched
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $transfers = $this->createMock(\Stripe\Service\TransferService::class);
        $transfers->expects($this->never())->method('createReversal');
        $stripe            = $this->createMock(StripeClient::class);
        $stripe->transfers = $transfers;

        (new ReverseStripeTransfer($lid, 're_notr'))->handle($stripe);

        $this->assertTrue(true); // no exception
    }

    // ── Failure / retry ───────────────────────────────────────────

    public function test_stripe_api_error_re_throws_for_laravel_retry(): void
    {
        $sid    = $this->insertSettlement();
        $lid    = $this->insertLine($sid, 'artist', 'tr_fail');
        $stripe = $this->makeStripeFailure();

        $this->expectException(ApiConnectionException::class);

        (new ReverseStripeTransfer($lid, 're_fail'))->handle($stripe);
    }

    public function test_line_status_unchanged_after_stripe_failure(): void
    {
        $sid    = $this->insertSettlement();
        $lid    = $this->insertLine($sid, 'artist', 'tr_unchanged');
        $stripe = $this->makeStripeFailure();

        try {
            (new ReverseStripeTransfer($lid, 're_unch'))->handle($stripe);
        } catch (ApiConnectionException) {
            // expected
        }

        // Line must still be 'transferred' so a retry will attempt again
        $this->assertSame('transferred', DB::table('settlement_lines')->find($lid)->status);
    }

    public function test_missing_line_does_not_throw(): void
    {
        $stripe = $this->makeStripeSuccess();
        (new ReverseStripeTransfer(999999, 're_missing'))->handle($stripe);
        $this->assertTrue(true);
    }
}
