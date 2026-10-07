<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\CloseAuctionItem;
use App\Domain\Auction\IssueCounterOffer;
use App\Domain\Auction\WaiveReserve;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\AuctionRuleset;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Reserve;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class ReserveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private StripeClient $stripe;
    private User         $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturn(null);
        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->seller = User::factory()->create(['role' => 'artist']);
    }

    private function makeItem(int $reservePriceCents, int $startingBidCents = 5000): array
    {
        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $ruleset = AuctionRuleset::create([
            'name'             => 'Reserve Auction',
            'reserve_enabled'  => true,
        ]);

        $auction = Auction::create([
            'title'      => 'Reserve Test',
            'slug'       => 'reserve-test-' . uniqid(),
            'venue_id'   => $venue->id,
            'ruleset_id' => $ruleset->id,
            'starts_at'  => now()->subDay(),
            'ends_at'    => now()->subMinute(),
            'status'     => 'closed',
            'currency'   => 'EUR',
        ]);

        $artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'Reserved Work',
            'slug'    => 'reserved-' . uniqid(),
            'status'  => 'in_auction',
        ]);

        $artLot = ArtLot::create([
            'artwork_id'          => $artwork->id,
            'consignor_id'        => $this->seller->id,
            'sale_mode'           => 'auction',
            'status'              => 'active',
            'starting_bid_cents'  => $startingBidCents,
            'reserve_price_cents' => $reservePriceCents,
            'currency'            => 'EUR',
        ]);

        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'open',
        ]);

        return [$item, $auction];
    }

    private function close(AuctionItem $item): void
    {
        (new CloseAuctionItem($this->stripe))->execute($item);
    }

    // ── Reserve not met ───────────────────────────────────────────

    public function test_lot_enters_reserve_not_met_when_bid_below_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 12000,   // below reserve of 20000
            'status'          => 'accepted',
        ]);

        $this->close($item);

        $this->assertDatabaseHas('auction_items', [
            'id'     => $item->id,
            'status' => 'reserve_not_met',
        ]);
    }

    public function test_reserve_record_created_with_correct_snapshot(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 12000,
            'status'          => 'accepted',
        ]);

        $this->close($item);

        $this->assertDatabaseHas('reserves', [
            'auction_item_id'    => $item->id,
            'reserve_price_cents' => 20000,
            'highest_bid_cents'  => 12000,
            'status'             => 'not_reached',
        ]);
    }

    public function test_lot_sold_normally_when_bid_meets_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 10000);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 10000,   // exactly at reserve
            'status'          => 'accepted',
        ]);

        $this->close($item);

        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'status' => 'sold']);
        $this->assertDatabaseCount('reserves', 0);
    }

    public function test_lot_sold_normally_when_bid_exceeds_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 10000);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 15000,
            'status'          => 'accepted',
        ]);

        $this->close($item);

        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'status' => 'sold']);
        $this->assertDatabaseCount('reserves', 0);
    }

    public function test_no_reserve_workflow_when_reserve_disabled_in_ruleset(): void
    {
        // Auction with reserve_enabled = false
        $region       = GeoRegion::create(['name' => 'Sofia2', 'slug' => 'sofia2', 'code' => 'S2']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia2', 'slug' => 'sofia2-s2', 'code' => 'S201']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia2', 'slug' => 'sofia2-99999', 'ekatte' => '99999', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'G2', 'slug' => 'g2', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $ruleset = AuctionRuleset::create(['name' => 'No Reserve', 'reserve_enabled' => false]);
        $auction = Auction::create([
            'title' => 'NR', 'slug' => 'nr-' . uniqid(), 'venue_id' => $venue->id,
            'ruleset_id' => $ruleset->id, 'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(), 'status' => 'closed', 'currency' => 'EUR',
        ]);
        $artwork = Artwork::create(['user_id' => $this->seller->id, 'title' => 'NR', 'slug' => 'nr-art-' . uniqid(), 'status' => 'in_auction']);
        $artLot  = ArtLot::create(['artwork_id' => $artwork->id, 'sale_mode' => 'auction', 'status' => 'active', 'starting_bid_cents' => 5000, 'reserve_price_cents' => 50000, 'currency' => 'EUR']);
        $item    = AuctionItem::create(['auction_id' => $auction->id, 'art_lot_id' => $artLot->id, 'lot_number' => 1, 'bid_increment_cents' => 500, 'status' => 'open']);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 6000, 'status' => 'accepted']);

        $this->close($item);

        // Sold despite bid being far below reserve — reserve_enabled = false
        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'status' => 'sold']);
        $this->assertDatabaseCount('reserves', 0);
    }

    public function test_reserve_not_met_is_idempotent(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);

        $this->close($item);
        $this->close($item->fresh()); // second call — no-op

        $this->assertDatabaseCount('reserves', 1);
        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'status' => 'reserve_not_met']);
    }

    // ── WaiveReserve ──────────────────────────────────────────────

    public function test_waive_reserve_marks_item_sold(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();

        (new WaiveReserve())->execute($reserve, $this->seller->id, 'Accepting below reserve');

        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'status' => 'sold']);
        $this->assertDatabaseHas('reserves', ['id' => $reserve->id, 'status' => 'waived']);
    }

    public function test_waive_reserve_sets_winner_bid(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        $bid = Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();
        (new WaiveReserve())->execute($reserve, $this->seller->id);

        $this->assertDatabaseHas('auction_items', ['id' => $item->id, 'winning_bid_id' => $bid->id]);
        $this->assertDatabaseHas('bids', ['id' => $bid->id, 'status' => 'won']);
    }

    public function test_waive_rejects_already_resolved_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();
        (new WaiveReserve())->execute($reserve, $this->seller->id);

        $this->expectException(\InvalidArgumentException::class);
        (new WaiveReserve())->execute($reserve->fresh(), $this->seller->id);
    }

    // ── IssueCounterOffer ─────────────────────────────────────────

    public function test_counter_offer_transitions_reserve_to_counter_offered(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();

        (new IssueCounterOffer())->execute($reserve, 15000, $this->seller->id);

        $this->assertDatabaseHas('reserves', [
            'id'                 => $reserve->id,
            'status'             => 'counter_offered',
            'counter_offer_cents' => 15000,
        ]);
        $this->assertNotNull($reserve->fresh()->counter_offer_expires_at);
    }

    public function test_counter_offer_rejects_price_at_or_below_highest_bid(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        (new IssueCounterOffer())->execute($reserve, 12000, $this->seller->id); // = highest bid
    }

    public function test_counter_offer_rejects_price_at_or_above_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        (new IssueCounterOffer())->execute($reserve, 20000, $this->seller->id); // = reserve
    }

    public function test_counter_offer_rejects_on_already_resolved_reserve(): void
    {
        [$item] = $this->makeItem(reservePriceCents: 20000);

        Bid::create(['auction_item_id' => $item->id, 'user_id' => User::factory()->create()->id, 'amount_cents' => 12000, 'status' => 'accepted']);
        $this->close($item);

        $reserve = Reserve::where('auction_item_id', $item->id)->firstOrFail();
        (new WaiveReserve())->execute($reserve, $this->seller->id);

        $this->expectException(\InvalidArgumentException::class);
        (new IssueCounterOffer())->execute($reserve->fresh(), 15000, $this->seller->id);
    }

    // ── Reserve model helpers ─────────────────────────────────────

    public function test_reserve_shortfall_cents(): void
    {
        $reserve = new Reserve([
            'reserve_price_cents' => 20000,
            'highest_bid_cents'   => 12000,
        ]);

        $this->assertSame(8000, $reserve->shortfallCents());
    }

    public function test_reserve_is_pending_only_when_not_reached(): void
    {
        $reserve = new Reserve(['status' => 'not_reached']);
        $this->assertTrue($reserve->isPending());

        $reserve->status = 'waived';
        $this->assertFalse($reserve->isPending());
    }
}
