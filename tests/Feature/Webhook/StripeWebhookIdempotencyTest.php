<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Application\Settlement\ProcessRefund;
use App\Domain\Auction\SettleAuction;
use App\Domain\Fulfillment\ConfirmSellNowPayment;
use App\Domain\Library\FinalizePaidBookPurchase;
use App\Domain\Payment\HandleConnectedAccountPayout;
use App\Domain\SellNow\CreateSellNowSettlement;
use App\Jobs\HandleStripeWebhook;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 141 — Payment-state verification.
 *
 * Invariants verified:
 *   1. Replaying checkout.session.completed cannot double-settle a SellNow offer.
 *   2. payment_intent.succeeded after SellNow settlement leaves ledger unchanged.
 *   3. checkout.session.completed with a non-accepted offer is silently skipped.
 *   4. payment_intent.amount_capturable_updated with open (not closed) auction is skipped.
 *   5. Webhook rows with status != 'received' are no-ops (idempotency guard).
 *   6. Webhook error resets status to 'received' so retries can proceed.
 */
class StripeWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private ArtLot $artLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'artist']);
        $this->buyer  = User::factory()->create(['role' => 'buyer']);

        $artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'Webhook Test Artwork',
            'slug'    => 'webhook-test-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'          => $artwork->id,
            'consignor_id'        => $this->seller->id,
            'sale_mode'           => 'sell_now',
            'status'              => 'active',
            'buy_now_price_cents' => 50000,
            'currency'            => 'EUR',
        ]);
    }

    // ── Guard: webhook status ──────────────────────────────────────────────────

    public function test_already_processed_webhook_is_no_op(): void
    {
        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_test_1', 'pi_test_1', offerId: 999),
            status: 'processed',
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
    }

    public function test_processing_webhook_is_no_op(): void
    {
        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_test_2', 'pi_test_2', offerId: 999),
            status: 'processing',
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processing');
    }

    // ── Guard: offer status for SellNow ───────────────────────────────────────

    public function test_checkout_session_completed_skips_non_accepted_offer(): void
    {
        $offer = SellNowOffer::create([
            'art_lot_id'                 => $this->artLot->id,
            'buyer_id'                   => $this->buyer->id,
            'offered_price_cents'        => 50000,
            'status'                     => 'submitted',
            'stripe_checkout_session_id' => 'cs_submitted_1',
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_submitted_1', 'pi_sub_1', offerId: $offer->id),
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $settlement = $this->mock(CreateSellNowSettlement::class);
        $settlement->shouldNotReceive('execute');

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
        $this->assertSame('submitted', $offer->fresh()->status);
    }

    public function test_checkout_session_completed_skips_already_paid_offer(): void
    {
        $offer = SellNowOffer::create([
            'art_lot_id'                 => $this->artLot->id,
            'buyer_id'                   => $this->buyer->id,
            'offered_price_cents'        => 50000,
            'agreed_price_cents'         => 50000,
            'status'                     => 'paid',
            'stripe_checkout_session_id' => 'cs_paid_1',
            'stripe_payment_intent_id'   => 'pi_paid_1',
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_paid_1', 'pi_paid_1', offerId: $offer->id),
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $settlement = $this->mock(CreateSellNowSettlement::class);
        $settlement->shouldNotReceive('execute');

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
        $this->assertSame('paid', $offer->fresh()->status);
    }

    public function test_checkout_session_completed_skips_mismatched_session_id(): void
    {
        $offer = SellNowOffer::create([
            'art_lot_id'                 => $this->artLot->id,
            'buyer_id'                   => $this->buyer->id,
            'offered_price_cents'        => 50000,
            'status'                     => 'accepted',
            'stripe_checkout_session_id' => 'cs_real_session',
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_different_session', 'pi_1', offerId: $offer->id),
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
    }

    // ── Double-settlement prevention ───────────────────────────────────────────

    /**
     * payment_intent.succeeded for a PI that belongs to an already-completed
     * SellNow settlement must not change the settlement status.
     *
     * The non-book path in handlePaymentIntentSucceeded does:
     *   UPDATE settlements SET status='completed' WHERE ... AND status='pending'
     * A SellNow settlement is created with status='completed', so the WHERE
     * guard prevents any update.  This test verifies that invariant explicitly.
     */
    public function test_payment_intent_succeeded_does_not_alter_completed_sell_now_settlement(): void
    {
        $piId = 'pi_sellnow_completed_1';

        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $piId,
            'gross_cents'              => 50000,
            'artist_cents'             => 22500,
            'fund_cents'               => 22500,
            'ops_cents'                => 5000,
            'currency'                 => 'EUR',
            'status'                   => 'completed',
            'stripe_event_id'          => 'evt_original',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'payment_intent.succeeded',
            payload: $this->paymentIntentPayload($piId, 50000),
        );

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');

        $settlement = DB::table('settlements')->find($settlementId);
        $this->assertSame('completed', $settlement->status, 'Settlement must remain completed');
    }

    public function test_payment_intent_succeeded_completes_pending_non_book_settlement(): void
    {
        $piId = 'pi_pending_settlement_1';

        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $piId,
            'gross_cents'              => 30000,
            'artist_cents'             => 13500,
            'fund_cents'               => 13500,
            'ops_cents'                => 3000,
            'currency'                 => 'EUR',
            'status'                   => 'pending',
            'stripe_event_id'          => 'evt_pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'payment_intent.succeeded',
            payload: $this->paymentIntentPayload($piId, 30000),
        );

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');

        $settlement = DB::table('settlements')->find($settlementId);
        $this->assertSame('completed', $settlement->status);
    }

    // ── Auction capture guard ──────────────────────────────────────────────────

    public function test_capturable_updated_skips_when_auction_not_closed(): void
    {
        $piId = 'pi_auction_open_1';

        $auctionId = DB::table('auctions')->insertGetId([
            'title'      => 'Open Auction',
            'slug'       => 'open-auction-' . uniqid(),
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('auction_items')->insertGetId([
            'auction_id' => $auctionId,
            'artwork_id' => $this->artLot->artwork_id,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bids')->insert([
            'auction_item_id'          => $itemId,
            'bidder_id'                => $this->buyer->id,
            'amount_cents'             => 60000,
            'currency'                 => 'EUR',
            'status'                   => 'won',
            'stripe_payment_intent_id' => $piId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $settleAuction = $this->mock(SettleAuction::class);
        $settleAuction->shouldNotReceive('execute');

        $eventId = $this->insertWebhookEvent(
            type: 'payment_intent.amount_capturable_updated',
            payload: $this->paymentIntentPayload($piId, 60000),
        );

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
    }

    public function test_capturable_updated_skips_when_no_matching_bid(): void
    {
        $settleAuction = $this->mock(SettleAuction::class);
        $settleAuction->shouldNotReceive('execute');

        $eventId = $this->insertWebhookEvent(
            type: 'payment_intent.amount_capturable_updated',
            payload: $this->paymentIntentPayload('pi_no_bid_' . uniqid(), 60000),
        );

        $this->dispatchJob($eventId);

        $this->assertWebhookStatus($eventId, 'processed');
    }

    // ── Replay / idempotency ───────────────────────────────────────────────────

    /**
     * Simulates replay: the second webhook event for the same checkout session
     * must be a no-op because the offer's status is no longer 'accepted'.
     * (After first processing it becomes 'paid'.)
     */
    public function test_replayed_checkout_session_is_no_op_after_offer_paid(): void
    {
        $offer = SellNowOffer::create([
            'art_lot_id'                 => $this->artLot->id,
            'buyer_id'                   => $this->buyer->id,
            'offered_price_cents'        => 50000,
            'agreed_price_cents'         => 50000,
            'status'                     => 'paid',
            'stripe_checkout_session_id' => 'cs_replay_1',
            'stripe_payment_intent_id'   => 'pi_replay_1',
        ]);

        $replayEventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_replay_1', 'pi_replay_1', offerId: $offer->id),
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldNotReceive('execute');

        $createSettlement = $this->mock(CreateSellNowSettlement::class);
        $createSettlement->shouldNotReceive('execute');

        $this->dispatchJob($replayEventId);

        $this->assertWebhookStatus($replayEventId, 'processed');
    }

    // ── Error path ────────────────────────────────────────────────────────────

    public function test_exception_during_processing_resets_status_to_received(): void
    {
        $offer = SellNowOffer::create([
            'art_lot_id'                 => $this->artLot->id,
            'buyer_id'                   => $this->buyer->id,
            'offered_price_cents'        => 50000,
            'agreed_price_cents'         => 50000,
            'status'                     => 'accepted',
            'stripe_checkout_session_id' => 'cs_fail_1',
        ]);

        $eventId = $this->insertWebhookEvent(
            type: 'checkout.session.completed',
            payload: $this->checkoutSessionPayload('cs_fail_1', 'pi_fail_1', offerId: $offer->id),
        );

        $confirmSellNow = $this->mock(ConfirmSellNowPayment::class);
        $confirmSellNow->shouldReceive('execute')->andThrow(new \RuntimeException('Stripe timeout'));

        try {
            $this->dispatchJob($eventId);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertWebhookStatus($eventId, 'received');

        $row = DB::table('webhook_events')->find($eventId);
        $this->assertStringContainsString('Stripe timeout', $row->error ?? '');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function insertWebhookEvent(string $type, array $payload, string $status = 'received'): int
    {
        return DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_test_' . uniqid(),
            'type'            => $type,
            'payload'         => json_encode($payload),
            'status'          => $status,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function dispatchJob(int $webhookEventId): void
    {
        $job = new HandleStripeWebhook($webhookEventId);
        $job->handle(
            app(ProcessRefund::class),
            app(FinalizePaidBookPurchase::class),
            app(HandleConnectedAccountPayout::class),
            app(SettleAuction::class),
            app(ConfirmSellNowPayment::class),
            app(CreateSellNowSettlement::class),
        );
    }

    private function assertWebhookStatus(int $id, string $expected): void
    {
        $row = DB::table('webhook_events')->find($id);
        $this->assertSame($expected, $row->status ?? null,
            "Expected webhook_events.status={$expected}");
    }

    private function checkoutSessionPayload(
        string $sessionId,
        string $piId,
        ?int $offerId = null,
        ?int $bookPurchaseId = null,
    ): array {
        $metadata = [];
        if ($offerId !== null) {
            $metadata['sell_now_offer_id'] = (string) $offerId;
        }
        if ($bookPurchaseId !== null) {
            $metadata['book_purchase_id'] = (string) $bookPurchaseId;
        }

        return [
            'id'   => 'evt_cs_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'             => $sessionId,
                    'payment_intent' => $piId,
                    'metadata'       => $metadata,
                ],
            ],
        ];
    }

    private function paymentIntentPayload(string $piId, int $amountCents): array
    {
        return [
            'id'   => 'evt_pi_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id'              => $piId,
                    'amount'          => $amountCents,
                    'amount_received' => $amountCents,
                    'currency'        => 'eur',
                ],
            ],
        ];
    }
}
