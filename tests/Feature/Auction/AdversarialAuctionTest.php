<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\BidRejected;
use App\Domain\Auction\CloseAuctionItem;
use App\Domain\Auction\EvaluateReserve;
use App\Domain\Auction\PlaceBid;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\DomainEvent;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Adversarial auction tests.
 *
 * These tests probe the behavioral invariants of the auction subsystem under
 * edge conditions: late bids, duplicate requests, reserve race, and outbid
 * handling. All scenarios exercise the real DB (arteuction_test / MariaDB),
 * which is necessary to verify the row-level-lock semantics of PlaceBid and
 * CloseAuctionItem.
 */
final class AdversarialAuctionTest extends TestCase
{
    use RefreshDatabase;

    private User         $bidder;
    private User         $bidder2;
    private Auction      $auction;
    private AuctionItem  $item;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $this->auction = Auction::create([
            'title'     => 'Adversarial Auction',
            'slug'      => 'adversarial-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artist  = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Adversarial Piece',
            'slug'    => 'adversarial-piece',
            'status'  => 'in_auction',
        ]);

        $artLot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'EUR',
        ]);

        $this->item = AuctionItem::create([
            'auction_id'          => $this->auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);

        $this->bidder  = User::factory()->create();
        $this->bidder2 = User::factory()->create();

        $this->stripe = $this->makeStripeMock();
    }

    // -----------------------------------------------------------------------
    // Bid rejected after item closes
    // -----------------------------------------------------------------------

    /**
     * A bid that arrives after CloseAuctionItem has already run must be
     * rejected — the item status is 'sold' or 'passed' and PlaceBid must
     * throw BidRejected (not silently accept the late bid).
     */
    public function test_bid_rejected_on_already_closed_item(): void
    {
        // Close without any bids — item goes to 'passed'
        $closeStripe = $this->makeStripeMockWithCancel();
        (new CloseAuctionItem($closeStripe))->execute($this->item);
        $this->item->refresh();

        $this->assertSame('passed', $this->item->status);

        $this->expectException(BidRejected::class);
        $this->expectExceptionMessageMatches('/not open/i');

        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_late_bid',
        );
    }

    // -----------------------------------------------------------------------
    // Outbid — previous accepted bid transitions to 'outbid'
    // -----------------------------------------------------------------------

    public function test_outbid_bid_transitions_to_outbid_status(): void
    {
        $action = new PlaceBid($this->stripe);

        $first = $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_first',
        );

        $second = $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder2->id,
            amountCents:           11000,
            stripePaymentMethodId: 'pm_second',
        );

        $this->assertSame('outbid', $first->fresh()->status);
        $this->assertSame('accepted', $second->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Minimum bid enforcement (anti-shill)
    // -----------------------------------------------------------------------

    public function test_bid_exactly_at_next_bid_threshold_accepted(): void
    {
        $action = new PlaceBid($this->stripe);

        // First bid: at starting price
        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_first',
        );

        // Second bid: starting + increment = 11000
        $second = $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder2->id,
            amountCents:           11000,
            stripePaymentMethodId: 'pm_second',
        );

        $this->assertSame('accepted', $second->fresh()->status);
    }

    public function test_bid_below_next_threshold_rejected(): void
    {
        $action = new PlaceBid($this->stripe);

        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_first',
        );

        $this->expectException(BidRejected::class);

        // 10500 is below starting (10000) + increment (1000) = 11000
        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder2->id,
            amountCents:           10500,
            stripePaymentMethodId: 'pm_under',
        );
    }

    // -----------------------------------------------------------------------
    // CloseAuctionItem idempotency
    // -----------------------------------------------------------------------

    /**
     * Calling CloseAuctionItem twice on the same item must be idempotent.
     * The second call must not create a duplicate domain event.
     */
    public function test_close_auction_item_is_idempotent(): void
    {
        // Place a bid so the item closes as 'sold'
        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_idempotent',
        );

        $closeStripe = $this->makeStripeMockWithCancel();

        (new CloseAuctionItem($closeStripe))->execute($this->item);
        (new CloseAuctionItem($closeStripe))->execute($this->item); // second call — no-op

        $this->assertSame(
            1,
            DomainEvent::where('event_type', 'auction_item.sold')->count(),
            'auction_item.sold should be emitted exactly once'
        );
    }

    // -----------------------------------------------------------------------
    // Reserve not met after close — only one Reserve record created
    // -----------------------------------------------------------------------

    public function test_evaluate_reserve_creates_exactly_one_reserve_record(): void
    {
        $this->item->artLot->update(['reserve_price_cents' => 50000]);

        // Bid below reserve
        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_reserve',
        );

        $reserve1 = (new EvaluateReserve())->execute($this->item, 10000);

        $this->assertSame('not_reached', $reserve1->status);
        $this->assertDatabaseCount('reserves', 1);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'reserve.not_met']);
    }

    // -----------------------------------------------------------------------
    // Winning bid emitted exactly once on close
    // -----------------------------------------------------------------------

    public function test_auction_item_sold_event_emitted_once_on_close(): void
    {
        $action = new PlaceBid($this->stripe);

        // Two bids — second wins
        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_b1',
        );

        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder2->id,
            amountCents:           11000,
            stripePaymentMethodId: 'pm_b2',
        );

        $closeStripe = $this->makeStripeMockWithCancel();
        (new CloseAuctionItem($closeStripe))->execute($this->item);

        $events = DomainEvent::where('event_type', 'auction_item.sold')->get();
        $this->assertCount(1, $events);
        $this->assertSame(11000, $events->first()->payload['winning_bid_cents']);
    }

    // -----------------------------------------------------------------------
    // Bid placed on sold item after concurrent close
    // -----------------------------------------------------------------------

    public function test_bid_on_sold_item_raises_bid_rejected(): void
    {
        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_before_close',
        );

        $closeStripe = $this->makeStripeMockWithCancel();
        (new CloseAuctionItem($closeStripe))->execute($this->item);
        $this->item->refresh();

        $this->assertSame('sold', $this->item->status);

        $this->expectException(BidRejected::class);

        // Concurrent late bid — must fail
        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder2->id,
            amountCents:           12000,
            stripePaymentMethodId: 'pm_after_close',
        );
    }

    // -----------------------------------------------------------------------
    // No bid row persisted when Stripe throws
    // -----------------------------------------------------------------------

    public function test_no_bid_persisted_when_stripe_throws(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willThrowException(new \Stripe\Exception\ApiConnectionException('timeout'));

        $brokenStripe = $this->createMock(StripeClient::class);
        $brokenStripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $before = Bid::count();

        try {
            (new PlaceBid($brokenStripe))->execute(
                item:                  $this->item,
                bidderId:              $this->bidder->id,
                amountCents:           10000,
                stripePaymentMethodId: 'pm_stripe_fail',
            );
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, Bid::count(), 'No bid row should be created when Stripe throws');
    }

    // -----------------------------------------------------------------------
    // Server-authoritative clock tests (Phase 14C)
    // -----------------------------------------------------------------------

    /**
     * Bid rejected when auction ends_at is in the past but status is still 'open'.
     * This is the race window CloseAuctionItem has not yet run.
     */
    public function test_bid_rejected_when_auction_time_expired_but_status_open(): void
    {
        // Wind the auction's ends_at into the past while keeping status 'open'
        $this->auction->update(['ends_at' => now()->subMinutes(5)]);
        $this->item->unsetRelation('auction'); // force reload

        $this->expectException(BidRejected::class);

        (new PlaceBid($this->makeStripeMock()))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_expired_clock',
        );
    }

    public function test_auction_item_is_open_for_bidding_uses_server_clock(): void
    {
        // Live auction — open
        $this->assertTrue($this->item->isOpenForBidding());

        // Expire the auction
        $this->auction->update(['ends_at' => now()->subSecond()]);
        $this->item->unsetRelation('auction');

        $this->assertFalse($this->item->isOpenForBidding());
    }

    public function test_auction_item_not_open_when_status_closed_regardless_of_clock(): void
    {
        // Status closed, time still valid
        $this->item->update(['status' => 'sold']);

        $this->assertFalse($this->item->isOpenForBidding());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeStripeMock(): StripeClient
    {
        $pi = $this->createMock(PaymentIntent::class);
        $pi->method('__get')->with('id')->willReturn('pi_test_adv');

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willReturn($pi);
        $piService->method('cancel')->willReturn(null);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        return $stripe;
    }

    private function makeStripeMockWithCancel(): StripeClient
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturn(null);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        return $stripe;
    }
}
