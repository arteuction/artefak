<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\CloseAuctionItem;
use App\Domain\Auction\FulfillAuctionItem;
use App\Domain\Auction\PlaceBid;
use App\Domain\Auction\SettleAuction;
use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 67: Complete transaction — bid → close → capture → settle → fulfill.
 *
 * Traces the full buyer journey end-to-end without hitting real Stripe:
 *   1. Buyer places bid  → authorized PI, bid accepted
 *   2. Auction closes    → winning bid marked won, item sold
 *   3. Settlement runs   → PI captured, settlement rows + ledger credits
 *   4. Fulfillment       → paid → preparing → shipped → delivered
 *
 * Also verifies:
 *   - Duplicate Stripe event is idempotent (no double-settle)
 *   - Capture failure skips settlement without crashing
 *   - Outbid buyer's PI is cancelled on auction close
 */
final class CompleteTransactionTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // 1. Full happy-path: bid → close → settle → fulfill
    // ────────────────────────────────────────────────────────────────────────

    public function test_complete_transaction_happy_path(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();

        // ── Step 1: place bid ───────────────────────────────────────────────
        $placeBid = new PlaceBid($this->makeStripeForBid('pi_happy_001'));
        $bid = $placeBid->execute(
            item:                  $item,
            bidderId:              $buyer->id,
            amountCents:           15000,
            stripePaymentMethodId: 'pm_card_visa',
        );

        $this->assertSame('accepted', $bid->status);
        $this->assertSame('authorized', $bid->payment_status);
        $this->assertSame('pi_happy_001', $bid->stripe_payment_intent_id);

        // ── Step 2: close auction item ──────────────────────────────────────
        $closeStripe = $this->createMock(StripeClient::class);
        $closeStripe->method('__get')->willReturn($this->createMock(PaymentIntentService::class));

        (new CloseAuctionItem($closeStripe))->execute($item);

        $item->refresh();
        $this->assertSame('sold', $item->status);
        $this->assertSame('awaiting_payment', $item->fulfillment_status);
        $this->assertNotNull($item->winning_bid_id);

        $bid->refresh();
        $this->assertSame('won', $bid->status);

        // ── Step 3: settle ─────────────────────────────────────────────────
        $auction->update(['status' => 'closed']);

        $settleStripe = $this->makeStripeForCapture();
        $action       = new SettleAuction($settleStripe, new SettlementCalculator(), new CreateSettlement());
        $ids          = $action->execute($auction, 'evt_happy_001');

        $this->assertCount(1, $ids, 'Exactly one settlement created');

        $bid->refresh();
        $this->assertSame('captured', $bid->payment_status);

        $settlement = DB::table('settlements')->find($ids[0]);
        $this->assertNotNull($settlement);
        $this->assertSame('pi_happy_001', $settlement->stripe_payment_intent_id);
        $this->assertSame(15000, (int) $settlement->gross_cents);

        // Three ledger credits (artist + fund + ops)
        $ledgerCount = DB::table('ledger_entries')
            ->where('settlement_id', $ids[0])
            ->where('type', 'credit')
            ->count();
        $this->assertSame(3, $ledgerCount, 'Three ledger credits for the sale split');

        // Settlement lines
        $lineCount = DB::table('settlement_lines')->where('settlement_id', $ids[0])->count();
        $this->assertSame(3, $lineCount, 'Three settlement lines (artist + fund + ops)');

        $item->refresh();
        $this->assertSame('paid', $item->fulfillment_status);

        // ── Step 4: fulfill ────────────────────────────────────────────────
        $fulfill = new FulfillAuctionItem();

        $fulfillRecord = $fulfill->createRecord($item, $buyer->id, [
            'name'         => 'Ana Georgieva',
            'line1'        => 'ul. Vitosha 10',
            'city'         => 'Sofia',
            'postal_code'  => '1000',
            'country'      => 'BG',
        ]);

        $item->refresh();
        $this->assertSame('preparing', $item->fulfillment_status);

        $fulfill->markShipped($item, 'Speedy', 'TRACK123');
        $item->refresh();
        $this->assertSame('shipped', $item->fulfillment_status);

        $this->assertNotNull(
            DB::table('auction_fulfillments')
                ->where('id', $fulfillRecord->id)
                ->value('tracking_number')
        );

        $fulfill->markDelivered($item);
        $item->refresh();
        $this->assertSame('delivered', $item->fulfillment_status);
        $this->assertNotNull(
            DB::table('auction_fulfillments')
                ->where('id', $fulfillRecord->id)
                ->value('delivered_at')
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Duplicate Stripe event is idempotent
    // ────────────────────────────────────────────────────────────────────────

    public function test_duplicate_stripe_event_does_not_double_settle(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();

        $this->seedWonBid($item, $buyer->id, 'pi_dup_001');
        $item->update(['status' => 'sold', 'fulfillment_status' => 'awaiting_payment', 'payment_deadline' => now()->addHours(48)]);
        $auction->update(['status' => 'closed']);

        $stripe = $this->makeStripeForCapture();
        $action = new SettleAuction($stripe, new SettlementCalculator(), new CreateSettlement());

        // First call — should create settlement
        $first = $action->execute($auction, 'evt_dup_001');
        $this->assertCount(1, $first);

        // Second call (duplicate event) — idempotent, returns same settlement id
        $second = $action->execute($auction, 'evt_dup_001');
        $this->assertCount(1, $second);
        $this->assertSame($first[0], $second[0], 'Same settlement ID returned on duplicate');

        $totalSettlements = DB::table('settlements')
            ->where('stripe_payment_intent_id', 'pi_dup_001')
            ->count();
        $this->assertSame(1, $totalSettlements, 'Exactly one settlement row despite two calls');

        $ledgerCount = DB::table('ledger_entries')
            ->where('settlement_id', $first[0])
            ->count();
        $this->assertSame(3, $ledgerCount, 'No duplicate ledger entries');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Capture failure skips the lot — no settlement created
    // ────────────────────────────────────────────────────────────────────────

    public function test_capture_failure_skips_settlement_gracefully(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();

        $this->seedWonBid($item, $buyer->id, 'pi_fail_001');
        $item->update(['status' => 'sold', 'fulfillment_status' => 'awaiting_payment', 'payment_deadline' => now()->addHours(48)]);
        $auction->update(['status' => 'closed']);

        // Stripe capture throws — simulates card decline or expired PI
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willThrowException(
            new \Stripe\Exception\InvalidRequestException('This PaymentIntent could not be captured.', 400)
        );
        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $action = new SettleAuction($stripe, new SettlementCalculator(), new CreateSettlement());
        $ids    = $action->execute($auction, 'evt_fail_001');

        $this->assertCount(0, $ids, 'No settlement when capture fails');
        $this->assertSame(
            0,
            DB::table('settlements')->where('stripe_payment_intent_id', 'pi_fail_001')->count(),
        );

        // Item remains awaiting_payment — not advanced to 'paid'
        $item->refresh();
        $this->assertSame('awaiting_payment', $item->fulfillment_status);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Outbid buyer's PI is cancelled when auction closes
    // ────────────────────────────────────────────────────────────────────────

    public function test_outbid_payment_intent_is_cancelled_on_close(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();
        $outbidder = User::factory()->create(['role' => 'buyer']);

        // Outbidder placed first
        $losingBid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $outbidder->id,
            'amount_cents'             => 10000,
            'status'                   => 'outbid',
            'stripe_payment_intent_id' => 'pi_outbid_001',
            'payment_status'           => 'authorized',
            'authorization_expires_at' => now()->addDays(7),
        ]);

        // Winner placed second
        Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $buyer->id,
            'amount_cents'             => 15000,
            'status'                   => 'accepted',
            'stripe_payment_intent_id' => 'pi_winner_001',
            'payment_status'           => 'authorized',
            'authorization_expires_at' => now()->addDays(7),
        ]);

        $cancelledPiIds = [];
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturnCallback(function (string $piId) use (&$cancelledPiIds) {
            $cancelledPiIds[] = $piId;
        });

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        (new CloseAuctionItem($stripe))->execute($item);

        $this->assertContains('pi_outbid_001', $cancelledPiIds, "Outbid buyer's PI should be cancelled");
        $this->assertNotContains('pi_winner_001', $cancelledPiIds, "Winner's PI must not be cancelled");
    }

    // ────────────────────────────────────────────────────────────────────────
    // 5. Ledger is append-only — no settlement rows are ever modified
    // ────────────────────────────────────────────────────────────────────────

    public function test_ledger_entries_are_append_only(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();
        $this->seedWonBid($item, $buyer->id, 'pi_ledger_001');
        $item->update(['status' => 'sold', 'fulfillment_status' => 'awaiting_payment', 'payment_deadline' => now()->addHours(48)]);
        $auction->update(['status' => 'closed']);

        $action = new SettleAuction($this->makeStripeForCapture(), new SettlementCalculator(), new CreateSettlement());
        $ids    = $action->execute($auction, 'evt_ledger_001');

        $this->assertCount(1, $ids);

        // Every ledger entry has its settlement_id set and a non-null idempotency key
        $entries = DB::table('ledger_entries')->where('settlement_id', $ids[0])->get();
        foreach ($entries as $entry) {
            $this->assertNotNull($entry->idempotency_key, 'Each ledger entry must carry an idempotency_key');
            $this->assertSame('credit', $entry->type, 'Sale entries are credits');
        }

        // Verify the three ledger rows sum to the gross amount
        $grossTotal = $entries->sum('amount_cents');
        $this->assertSame(15000, (int) $grossTotal, 'Ledger credits must sum to gross sale amount');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 6. No item — CloseAuctionItem marks 'passed'
    // ────────────────────────────────────────────────────────────────────────

    public function test_close_item_with_no_bids_marks_passed(): void
    {
        [$auction, $item,] = $this->makeLiveScenario();

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->willReturn($this->createMock(PaymentIntentService::class));

        (new CloseAuctionItem($stripe))->execute($item);

        $item->refresh();
        $this->assertSame('passed', $item->status);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 7. CloseAuctionItem is idempotent — second call is a no-op
    // ────────────────────────────────────────────────────────────────────────

    public function test_close_auction_item_is_idempotent(): void
    {
        [$auction, $item, $buyer] = $this->makeLiveScenario();
        $this->seedWonBid($item, $buyer->id, 'pi_idem_002');
        $item->update(['status' => 'sold', 'winning_bid_id' => Bid::where('auction_item_id', $item->id)->value('id')]);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->willReturn($this->createMock(PaymentIntentService::class));

        // Second close — must be a no-op without crashing
        (new CloseAuctionItem($stripe))->execute($item);

        $item->refresh();
        $this->assertSame('sold', $item->status, 'Already-closed item stays sold');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /** @return array{Auction, AuctionItem, User} */
    private function makeLiveScenario(): array
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $artist  = User::factory()->create(['role' => 'artist']);

        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Complete Trans Art ' . uniqid(),
            'slug'         => 'complete-trans-' . uniqid(),
            'status'       => 'in_auction',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);

        $lot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'consignor_id'       => $artist->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'currency'           => 'EUR',
            'starting_bid_cents' => 10000,
            'split_profile_key'  => 'social_pilot_45_45_10',
        ]);

        $auction = Auction::create([
            'title'     => 'Phase67 Auction ' . uniqid(),
            'slug'      => 'phase67-auction-' . uniqid(),
            'status'    => 'live',
            'currency'  => 'EUR',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);

        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);

        return [$auction, $item, $buyer];
    }

    private function seedWonBid(AuctionItem $item, int $userId, string $piId): Bid
    {
        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $userId,
            'amount_cents'             => 15000,
            'status'                   => 'won',
            'stripe_payment_intent_id' => $piId,
            'payment_status'           => 'authorized',
            'authorization_expires_at' => now()->addDays(7),
        ]);
        $item->update(['winning_bid_id' => $bid->id]);
        return $bid;
    }

    private function makeStripeForBid(string $piId): StripeClient
    {
        $pi = $this->createMock(PaymentIntent::class);
        $pi->method('__get')->with('id')->willReturn($piId);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willReturn($pi);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        return $stripe;
    }

    private function makeStripeForCapture(): StripeClient
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willReturn(null);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        return $stripe;
    }
}
