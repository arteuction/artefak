<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\BidRejected;
use App\Domain\Auction\PlaceBid;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class PlaceBidTest extends TestCase
{
    use RefreshDatabase;

    private User        $bidder;
    private Auction     $auction;
    private AuctionItem $item;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bidder = User::factory()->create();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);

        $venue = Venue::create(['name' => 'Test Gallery', 'slug' => 'test-gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $this->auction = Auction::create([
            'title'     => 'Test Auction',
            'slug'      => 'test-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artist  = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork',
            'status'  => 'in_auction',
        ]);

        $this->item = AuctionItem::create([
            'auction_id'          => $this->auction->id,
            'artwork_id'          => $artwork->id,
            'lot_number'          => 1,
            'starting_bid_cents'  => 10000,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);

        // Mock Stripe
        $pi = $this->createMock(PaymentIntent::class);
        $pi->id = 'pi_test_place_bid';

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willReturn($pi);

        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($piService);
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_places_bid_at_starting_price(): void
    {
        $action = new PlaceBid($this->stripe);

        $bid = $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_test_visa',
        );

        $fresh = $bid->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame(10000, $fresh->amount_cents);
        $this->assertSame('pi_test_place_bid', $fresh->stripe_payment_intent_id);
        $this->assertDatabaseHas('bids', ['id' => $bid->id, 'status' => 'accepted']);
    }

    public function test_outbids_previous_accepted_bid(): void
    {
        $action = new PlaceBid($this->stripe);

        $first = $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_test_visa',
        );

        $second = $action->execute(
            item:                  $this->item->fresh(),
            bidderId:              User::factory()->create()->id,
            amountCents:           11000,
            stripePaymentMethodId: 'pm_test_mc',
        );

        $this->assertSame('outbid',    $first->fresh()->status);
        $this->assertSame('accepted',  $second->status);
    }

    public function test_next_bid_cents_advances_by_increment(): void
    {
        $action = new PlaceBid($this->stripe);

        $action->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_test_visa',
        );

        $this->assertSame(11000, $this->item->fresh()->nextBidCents());
    }

    // ── Rejection paths ───────────────────────────────────────────

    public function test_rejects_bid_below_minimum(): void
    {
        $this->expectException(BidRejected::class);

        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           9999,
            stripePaymentMethodId: 'pm_test_visa',
        );
    }

    public function test_rejects_bid_on_closed_item(): void
    {
        $this->item->update(['status' => 'sold']);

        $this->expectException(BidRejected::class);

        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_test_visa',
        );
    }

    public function test_rejects_bid_on_passed_item(): void
    {
        $this->item->update(['status' => 'passed']);

        $this->expectException(BidRejected::class);

        (new PlaceBid($this->stripe))->execute(
            item:                  $this->item,
            bidderId:              $this->bidder->id,
            amountCents:           10000,
            stripePaymentMethodId: 'pm_test_visa',
        );
    }

    // ── Atomicity ─────────────────────────────────────────────────

    public function test_no_bid_row_created_when_stripe_throws(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willThrowException(new \Stripe\Exception\ApiConnectionException('network'));

        $badStripe = $this->createMock(StripeClient::class);
        $badStripe->method('__get')->with('paymentIntents')->willReturn($piService);

        try {
            (new PlaceBid($badStripe))->execute(
                item:                  $this->item,
                bidderId:              $this->bidder->id,
                amountCents:           10000,
                stripePaymentMethodId: 'pm_test_visa',
            );
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseCount('bids', 0);
    }
}
