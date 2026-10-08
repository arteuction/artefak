<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Auction\CloseAuctionItem;
use App\Domain\Auction\EvaluateReserve;
use App\Domain\Auction\IssueCounterOffer;
use App\Domain\Auction\WaiveReserve;
use App\Domain\Donation\RecordDonation;
use App\Domain\Fulfillment\CloseSellNow;
use App\Domain\Fulfillment\ConfirmAuctionDelivery;
use App\Domain\Fulfillment\ConfirmSellNowDelivery;
use App\Domain\Fulfillment\ConfirmSellNowPayment;
use App\Domain\Fulfillment\ShipAuctionItem;
use App\Domain\Impact\ImpactMetric;
use App\Domain\Impact\RecordImpactEvent;
use App\Domain\Outbox\AppendDomainEvent;
use App\Domain\SellNow\AcceptOffer;
use App\Domain\SellNow\CounterOffer;
use App\Domain\SellNow\SubmitOffer;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionFulfillment;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\DomainEvent;
use App\Models\DonationRecipient;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\SellNowOffer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

final class EventCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private AuctionItem  $auctionItem;
    private User         $bidder;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        // Full auction fixture (Stripe mock + Geo chain)
        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'Test Auction',
            'slug'      => 'test-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artist  = User::create(['name' => 'Artist', 'email' => 'artist@t.com', 'password' => 'x', 'role' => 'seller']);
        $artwork = Artwork::create(['user_id' => $artist->id, 'title' => 'Piece', 'slug' => 'piece', 'status' => 'listed']);
        $lot     = ArtLot::create(['artwork_id' => $artwork->id, 'consignor_id' => $artist->id, 'status' => 'active', 'sale_mode' => 'auction', 'currency' => 'EUR']);

        $this->auctionItem = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'open',
        ]);

        $this->bidder = User::create(['name' => 'Bidder', 'email' => 'bidder@t.com', 'password' => 'x', 'role' => 'buyer']);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturn(null);

        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($piService);
    }

    // -----------------------------------------------------------------------
    // event_version field
    // -----------------------------------------------------------------------

    public function test_event_version_defaults_to_1(): void
    {
        $lot = $this->auctionItem->artLot;

        (new AppendDomainEvent())->execute(
            aggregate: $lot,
            eventType: 'test.event',
            payload:   ['foo' => 'bar'],
        );

        $event = DomainEvent::where('event_type', 'test.event')->firstOrFail();
        $this->assertSame(1, $event->event_version);
    }

    public function test_event_version_can_be_set_explicitly(): void
    {
        $lot = $this->auctionItem->artLot;

        (new AppendDomainEvent())->execute(
            aggregate:    $lot,
            eventType:    'test.event.v2',
            payload:      ['foo' => 'bar'],
            eventVersion: 2,
        );

        $event = DomainEvent::where('event_type', 'test.event.v2')->firstOrFail();
        $this->assertSame(2, $event->event_version);
    }

    // -----------------------------------------------------------------------
    // Auction events (bid created directly — no Stripe needed for PlaceBid)
    // -----------------------------------------------------------------------

    public function test_close_auction_item_emits_auction_item_sold(): void
    {
        $bid = $this->makeAcceptedBid(10000);

        (new CloseAuctionItem($this->stripe))->execute($this->auctionItem);

        $event = DomainEvent::where('event_type', 'auction_item.sold')->firstOrFail();
        $this->assertSame(10000, $event->payload['winning_bid_cents']);
    }

    public function test_evaluate_reserve_emits_reserve_not_met(): void
    {
        // Art lot needs a reserve price set for EvaluateReserve to work
        $this->auctionItem->artLot->update(['reserve_price_cents' => 50000]);

        (new EvaluateReserve())->execute($this->auctionItem, 100);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'reserve.not_met']);
    }

    public function test_waive_reserve_emits_reserve_waived(): void
    {
        $this->auctionItem->artLot->update(['reserve_price_cents' => 50000]);
        $this->makeAcceptedBid(100);
        $reserve = (new EvaluateReserve())->execute($this->auctionItem, 100);

        (new WaiveReserve())->execute($reserve, $this->bidder->id);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'reserve.waived']);
    }

    public function test_issue_counter_offer_emits_reserve_counter_offered(): void
    {
        $this->auctionItem->artLot->update(['reserve_price_cents' => 50000]);
        $this->makeAcceptedBid(100);
        $reserve = (new EvaluateReserve())->execute($this->auctionItem, 100);

        (new IssueCounterOffer())->execute($reserve, 20000, $this->bidder->id);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'reserve.counter_offered']);
    }

    // -----------------------------------------------------------------------
    // SellNow events
    // -----------------------------------------------------------------------

    public function test_submit_offer_emits_offer_submitted(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();

        (new SubmitOffer())->execute($lot, $buyer, 5000);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'offer.submitted']);
    }

    public function test_counter_offer_emits_offer_countered(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();
        $offer = (new SubmitOffer())->execute($lot, $buyer, 5000);

        (new CounterOffer())->execute($offer, 6000);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'offer.countered']);
    }

    public function test_accept_offer_emits_offer_accepted(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();
        $offer = (new SubmitOffer())->execute($lot, $buyer, 5000);

        (new AcceptOffer())->execute($offer);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'offer.accepted']);
    }

    // -----------------------------------------------------------------------
    // Fulfillment events
    // -----------------------------------------------------------------------

    public function test_ship_auction_item_emits_artwork_shipped(): void
    {
        $this->makeAcceptedBid(10000);
        (new CloseAuctionItem($this->stripe))->execute($this->auctionItem);
        $this->auctionItem->refresh();

        AuctionFulfillment::create([
            'auction_item_id'      => $this->auctionItem->id,
            'winner_user_id'       => $this->bidder->id,
            'shipping_name'        => 'Test Buyer',
            'shipping_line1'       => '1 Main St',
            'shipping_city'        => 'Sofia',
            'shipping_postal_code' => '1000',
            'shipping_country'     => 'BG',
        ]);
        $this->auctionItem->update(['fulfillment_status' => 'paid']);

        (new ShipAuctionItem())->execute($this->auctionItem, 'DHL', 'TRACK123');

        $this->assertDatabaseHas('domain_events', ['event_type' => 'artwork.shipped']);
    }

    public function test_confirm_sell_now_payment_emits_payment_confirmed(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();
        $offer = (new SubmitOffer())->execute($lot, $buyer, 5000);
        (new AcceptOffer())->execute($offer);
        $offer->refresh();

        (new ConfirmSellNowPayment())->execute($offer);

        $event = DomainEvent::where('event_type', 'payment.confirmed')->firstOrFail();
        $this->assertSame('sell_now', $event->payload['channel']);
    }

    public function test_confirm_sell_now_delivery_emits_artwork_delivered(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();
        $offer = (new SubmitOffer())->execute($lot, $buyer, 5000);
        (new AcceptOffer())->execute($offer);
        $offer->refresh();
        (new ConfirmSellNowPayment())->execute($offer);
        $offer->refresh();

        (new ConfirmSellNowDelivery())->execute($offer);

        $event = DomainEvent::where('event_type', 'artwork.delivered')->firstOrFail();
        $this->assertSame('sell_now', $event->payload['channel']);
    }

    public function test_confirm_auction_delivery_emits_artwork_delivered_and_ownership_transferred(): void
    {
        $this->makeAcceptedBid(10000);
        (new CloseAuctionItem($this->stripe))->execute($this->auctionItem);
        $this->auctionItem->refresh();

        AuctionFulfillment::create([
            'auction_item_id'      => $this->auctionItem->id,
            'winner_user_id'       => $this->bidder->id,
            'shipping_name'        => 'Test Buyer',
            'shipping_line1'       => '1 Main St',
            'shipping_city'        => 'Sofia',
            'shipping_postal_code' => '1000',
            'shipping_country'     => 'BG',
        ]);
        $this->auctionItem->update(['fulfillment_status' => 'paid']);
        (new ShipAuctionItem())->execute($this->auctionItem, 'DHL', 'TRACK456');
        $this->auctionItem->refresh();

        (new ConfirmAuctionDelivery())->execute($this->auctionItem);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'artwork.delivered']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'ownership.transferred']);
    }

    public function test_close_sell_now_emits_ownership_transferred(): void
    {
        [, $lot, $buyer] = $this->makeSellNowLot();
        $offer = (new SubmitOffer())->execute($lot, $buyer, 5000);
        (new AcceptOffer())->execute($offer);
        $offer->refresh();
        (new ConfirmSellNowPayment())->execute($offer);
        $offer->refresh();
        (new ConfirmSellNowDelivery())->execute($offer);
        $offer->refresh();

        (new CloseSellNow())->execute($offer);

        $this->assertDatabaseHas('domain_events', ['event_type' => 'ownership.transferred']);
    }

    // -----------------------------------------------------------------------
    // Donation / Impact events
    // -----------------------------------------------------------------------

    public function test_record_donation_emits_donation_recorded(): void
    {
        $donor     = User::create(['name' => 'Donor', 'email' => 'donor@t.com', 'password' => 'x', 'role' => 'buyer']);
        $recipient = DonationRecipient::create([
            'name'              => 'Org',
            'eik'               => 'BG123456789',
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => 'active',
        ]);

        (new RecordDonation())->execute($recipient, $donor, 10000, 'dk-001');

        $event = DomainEvent::where('event_type', 'donation.recorded')->firstOrFail();
        $this->assertSame(10000, $event->payload['donated_cents']);
    }

    public function test_record_impact_event_emits_impact_recorded(): void
    {
        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $this->auctionItem->artLot->artwork_id,
            'sdg_number' => 4,
            'rationale'  => 'Promotes quality education',
            'status'     => 'approved',
        ]);

        (new RecordImpactEvent())->execute($claim, ImpactMetric::AudienceReach, 100, 'imp-001');

        $event = DomainEvent::where('event_type', 'impact.recorded')->firstOrFail();
        $this->assertSame(4, $event->payload['sdg_number']);
        $this->assertSame(100, $event->payload['magnitude']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAcceptedBid(int $amountCents): Bid
    {
        return Bid::create([
            'auction_item_id' => $this->auctionItem->id,
            'user_id'         => $this->bidder->id,
            'amount_cents'    => $amountCents,
            'bid_type'        => 'live',
            'status'          => 'accepted',
        ]);
    }

    /** @return array{0: Artwork, 1: ArtLot, 2: User} */
    private function makeSellNowLot(): array
    {
        $seller  = User::create(['name' => 'Seller', 'email' => 'sel'.uniqid().'@t.com', 'password' => 'x', 'role' => 'seller']);
        $artwork = Artwork::create(['user_id' => $seller->id, 'title' => 'SN', 'slug' => 'sn-'.uniqid(), 'status' => 'listed']);
        $lot     = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'consignor_id'       => $seller->id,
            'status'             => 'active',
            'sale_mode'          => 'sell_now',
            'currency'           => 'EUR',
            'asking_price_cents' => 10000,
        ]);
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buy'.uniqid().'@t.com', 'password' => 'x', 'role' => 'buyer']);

        return [$artwork, $lot, $buyer];
    }
}
