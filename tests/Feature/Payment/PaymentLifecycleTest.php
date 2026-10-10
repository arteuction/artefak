<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Settlement\ProcessRefund;
use App\Domain\Auction\SettleAuction;
use App\Domain\Fulfillment\ConfirmSellNowPayment;
use App\Domain\Library\FinalizePaidBookPurchase;
use App\Domain\Payment\HandleConnectedAccountPayout;
use App\Domain\SellNow\CreateSellNowSettlement;
use App\Jobs\HandleStripeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 79 — Stripe payment lifecycle: webhook receipt → job execution → DB state.
 *
 * The existing WebhookInboxTest covers signature verification and job queuing.
 * These tests dispatch HandleStripeWebhook SYNCHRONOUSLY (no Queue::fake) to verify
 * the complete state machine after each event type is processed.
 *
 * Scenarios:
 *   A. payment_intent.succeeded  → settlement.status = 'completed'
 *   B. payment_intent.succeeded  → duplicate event is idempotent
 *   C. payment_intent.payment_failed → settlement stays 'pending' (awaiting manual review)
 *   D. charge.refunded (succeeded)   → refund created, ledger debit, settlement 'refunded'
 *   E. charge.refunded (partial)     → settlement 'partially_refunded'
 *   F. charge.refunded (non-succeeded status) → audit row only, no ledger change
 *   G. payout.paid (connected account) → payout record updated
 *   H. Unknown event type           → job marks 'processed', no crash
 */
final class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ProcessRefund              $processRefund;
    private FinalizePaidBookPurchase   $finalizeBook;
    private HandleConnectedAccountPayout $handlePayout;
    private SettleAuction              $settleAuction;
    private ConfirmSellNowPayment      $confirmSellNow;
    private CreateSellNowSettlement    $createSellNowSettlement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processRefund           = $this->app->make(ProcessRefund::class);
        $this->finalizeBook            = $this->app->make(FinalizePaidBookPurchase::class);
        $this->handlePayout            = $this->app->make(HandleConnectedAccountPayout::class);
        $this->settleAuction           = $this->app->make(SettleAuction::class);
        $this->confirmSellNow          = $this->app->make(ConfirmSellNowPayment::class);
        $this->createSellNowSettlement = $this->app->make(CreateSellNowSettlement::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSettlement(string $piId, int $grossCents = 10000, string $status = 'pending'): int
    {
        return (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $piId,
            'stripe_event_id'          => 'evt_seed_' . uniqid(),
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
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function makeSettlementLines(int $settlementId, int $grossCents): void
    {
        foreach (['artist' => 4500, 'fund' => 4500, 'ops' => 1000] as $type => $bps) {
            $amount = (int) ($grossCents * $bps / 10000);
            DB::table('settlement_lines')->insert([
                'settlement_id'    => $settlementId,
                'recipient_type'   => $type,
                'entity_name'      => ucfirst($type),
                'entity_role'      => $type,
                'amount_cents'     => $amount,
                'currency'         => 'EUR',
                'weight'           => $bps,
                'status'           => 'pending',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    /** Insert a webhook_event row and return its id */
    private function storeWebhookEvent(string $type, string $eventId, array $object = []): int
    {
        $payload = json_encode(['id' => $eventId, 'type' => $type, 'data' => ['object' => $object]]);

        return (int) DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => $eventId,
            'type'            => $type,
            'payload'         => $payload,
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function storeChargeRefundedEvent(string $eventId, string $piId, string $refundId, int $amount, string $refundStatus = 'succeeded'): int
    {
        $payload = json_encode([
            'id'   => $eventId,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id'             => 'ch_test',
                    'payment_intent' => $piId,
                    'refunds'        => ['data' => [[
                        'id'             => $refundId,
                        'amount'         => $amount,
                        'currency'       => 'eur',
                        'status'         => $refundStatus,
                    ]]],
                ],
            ],
        ]);

        return (int) DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => $eventId,
            'type'            => 'charge.refunded',
            'payload'         => $payload,
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /** Run the webhook job synchronously */
    private function runJob(int $webhookEventId): void
    {
        $job = new HandleStripeWebhook($webhookEventId);
        $job->handle($this->processRefund, $this->finalizeBook, $this->handlePayout, $this->settleAuction, $this->confirmSellNow, $this->createSellNowSettlement);
    }

    // ── A. payment_intent.succeeded → settlement completed ────────────────────

    public function test_payment_intent_succeeded_marks_settlement_completed(): void
    {
        $piId = 'pi_succeed_001';
        $this->makeSettlement($piId);

        $rowId = $this->storeWebhookEvent(
            'payment_intent.succeeded',
            'evt_succeed_001',
            ['id' => $piId, 'amount_received' => 10000, 'currency' => 'eur'],
        );

        $this->runJob($rowId);

        $this->assertSame(
            'completed',
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->value('status'),
        );

        $this->assertSame(
            'processed',
            DB::table('webhook_events')->find($rowId)->status,
        );
    }

    // ── B. Duplicate payment_intent.succeeded is idempotent ───────────────────

    public function test_duplicate_payment_succeeded_does_not_double_complete(): void
    {
        $piId = 'pi_succeed_idem';
        $this->makeSettlement($piId, 10000, 'completed');

        $rowId = $this->storeWebhookEvent(
            'payment_intent.succeeded',
            'evt_succeed_idem',
            ['id' => $piId, 'amount_received' => 10000, 'currency' => 'eur'],
        );

        $this->runJob($rowId);

        // Still completed, not changed to another status
        $this->assertSame(
            'completed',
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->value('status'),
        );

        $this->assertSame(
            'processed',
            DB::table('webhook_events')->find($rowId)->status,
        );
    }

    // ── C. payment_intent.payment_failed → settlement stays pending ───────────

    public function test_payment_intent_failed_leaves_settlement_pending(): void
    {
        $piId = 'pi_fail_001';
        $this->makeSettlement($piId);

        $rowId = $this->storeWebhookEvent(
            'payment_intent.payment_failed',
            'evt_fail_001',
            ['id' => $piId],
        );

        $this->runJob($rowId);

        // Business rule: failed payment → manual review, not auto-failed
        $this->assertSame(
            'pending',
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->value('status'),
        );

        $this->assertSame('processed', DB::table('webhook_events')->find($rowId)->status);
    }

    // ── D. charge.refunded (full) → settlement refunded ──────────────────────

    public function test_charge_refunded_succeeded_marks_settlement_refunded(): void
    {
        $piId  = 'pi_refund_full';
        $gross = 10000;
        $sId   = $this->makeSettlement($piId, $gross, 'completed');
        $this->makeSettlementLines($sId, $gross);

        $rowId = $this->storeChargeRefundedEvent('evt_ref_full', $piId, 're_full_001', $gross);
        $this->runJob($rowId);

        $this->assertSame(
            'refunded',
            DB::table('settlements')->find($sId)->status,
        );

        // Ledger should have debit entries summing to gross
        $debitTotal = (int) DB::table('ledger_entries')
            ->where('settlement_id', $sId)
            ->where('type', 'debit')
            ->sum('amount_cents');

        $this->assertSame($gross, $debitTotal);
    }

    // ── E. charge.refunded (partial) → settlement partially_refunded ──────────

    public function test_charge_refunded_partial_marks_settlement_partially_refunded(): void
    {
        $piId  = 'pi_refund_partial';
        $gross = 10000;
        $sId   = $this->makeSettlement($piId, $gross, 'completed');
        $this->makeSettlementLines($sId, $gross);

        $rowId = $this->storeChargeRefundedEvent('evt_ref_partial', $piId, 're_partial_001', 5000); // half
        $this->runJob($rowId);

        $this->assertSame(
            'partially_refunded',
            DB::table('settlements')->find($sId)->status,
        );

        $debitTotal = (int) DB::table('ledger_entries')
            ->where('settlement_id', $sId)
            ->where('type', 'debit')
            ->sum('amount_cents');

        $this->assertSame(5000, $debitTotal);
    }

    // ── F. charge.refunded (non-succeeded) → audit only, no ledger ───────────

    public function test_charge_refunded_pending_status_creates_audit_row_only(): void
    {
        $piId = 'pi_refund_pending';
        $sId  = $this->makeSettlement($piId, 10000, 'completed');
        $this->makeSettlementLines($sId, 10000);

        // Stripe sends refund with status 'pending' (not yet processed by bank)
        $rowId = $this->storeChargeRefundedEvent('evt_ref_pend', $piId, 're_pend_001', 10000, 'pending');
        $this->runJob($rowId);

        // Settlement status must not change
        $this->assertSame('completed', DB::table('settlements')->find($sId)->status);

        // Refund row exists (audit) but no ledger debits
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => 're_pend_001', 'refund_status' => 'pending']);

        $debitCount = DB::table('ledger_entries')
            ->where('settlement_id', $sId)
            ->where('type', 'debit')
            ->count();
        $this->assertSame(0, $debitCount);
    }

    // ── G. payout.paid event → payout record updated ─────────────────────────

    public function test_payout_paid_event_is_processed_without_error(): void
    {
        // Connected account payouts land on the payout.paid event.
        // We verify the job processes without exception and marks the event processed.
        $payload = json_encode([
            'id'      => 'evt_payout_paid',
            'type'    => 'payout.paid',
            'account' => 'acct_test_connected',
            'data'    => ['object' => [
                'id'             => 'po_test',
                'amount'         => 4500,
                'currency'       => 'eur',
                'arrival_date'   => time(),
                'failure_code'   => null,
                'failure_message' => null,
            ]],
        ]);

        $rowId = (int) DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_payout_paid',
            'type'            => 'payout.paid',
            'payload'         => $payload,
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->runJob($rowId);

        $this->assertSame('processed', DB::table('webhook_events')->find($rowId)->status);
    }

    // ── H. Unknown event type → processed without crash ──────────────────────

    public function test_unknown_event_type_is_processed_without_crash(): void
    {
        $rowId = $this->storeWebhookEvent('some.unknown.event', 'evt_unknown_001', []);

        $this->runJob($rowId);

        $this->assertSame('processed', DB::table('webhook_events')->find($rowId)->status);
    }

    // ── Webhook status transitions ────────────────────────────────────────────

    public function test_job_skips_already_processed_event(): void
    {
        $piId  = 'pi_skip_processed';
        $this->makeSettlement($piId);

        // Event is already 'processed' — another worker already handled it
        $rowId = (int) DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_already_done',
            'type'            => 'payment_intent.succeeded',
            'payload'         => json_encode([
                'id' => 'evt_already_done', 'type' => 'payment_intent.succeeded',
                'data' => ['object' => ['id' => $piId, 'currency' => 'eur', 'amount_received' => 10000]],
            ]),
            'status'          => 'processed', // already done
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->runJob($rowId);

        // Settlement must still be 'pending' — the job bailed on the status guard
        $this->assertSame(
            'pending',
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->value('status'),
        );
    }
}
