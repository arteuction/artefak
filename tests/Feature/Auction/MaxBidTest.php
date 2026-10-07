<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\BidRejected;
use App\Domain\Auction\PlaceMaxBid;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\MaxBid;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaxBidTest extends TestCase
{
    use RefreshDatabase;

    private AuctionItem $item;
    private User        $bidder;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'Max Bid Auction',
            'slug'      => 'max-bid-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artwork = Artwork::create([
            'user_id' => User::factory()->create()->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork',
            'status'  => 'in_auction',
        ]);

        $artLot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'EUR',
        ]);

        $this->item   = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);

        $this->bidder = User::factory()->create();
    }

    private function action(): PlaceMaxBid
    {
        return new PlaceMaxBid();
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_places_max_bid_at_or_above_minimum(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        $this->assertSame('active',             $maxBid->status);
        $this->assertSame(50000,                $maxBid->ceiling_cents);
        $this->assertSame('EUR',                $maxBid->currency);
        $this->assertSame($this->bidder->id,    $maxBid->user_id);
        $this->assertSame($this->item->id,      $maxBid->auction_item_id);
    }

    public function test_ceiling_exactly_at_minimum_is_accepted(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 10000);

        $this->assertSame(10000, $maxBid->ceiling_cents);
    }

    // ── Rejection paths ───────────────────────────────────────────

    public function test_rejects_ceiling_below_minimum_bid(): void
    {
        $this->expectException(BidRejected::class);

        $this->action()->execute($this->item, $this->bidder->id, 9999);
    }

    public function test_rejects_on_sold_item(): void
    {
        $this->item->update(['status' => 'sold']);

        $this->expectException(BidRejected::class);

        $this->action()->execute($this->item, $this->bidder->id, 50000);
    }

    public function test_rejects_on_passed_item(): void
    {
        $this->item->update(['status' => 'passed']);

        $this->expectException(BidRejected::class);

        $this->action()->execute($this->item, $this->bidder->id, 50000);
    }

    // ── Replacing a max bid ───────────────────────────────────────

    public function test_higher_ceiling_replaces_existing_active_max_bid(): void
    {
        $first = $this->action()->execute($this->item, $this->bidder->id, 20000);

        $second = $this->action()->execute($this->item->fresh(), $this->bidder->id, 30000);

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('active',    $second->status);
        $this->assertSame(30000,       $second->ceiling_cents);

        // Only one active max bid per bidder per item
        $this->assertSame(1, MaxBid::where('auction_item_id', $this->item->id)
            ->where('user_id', $this->bidder->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_equal_or_lower_ceiling_is_rejected_when_active_max_bid_exists(): void
    {
        $this->action()->execute($this->item, $this->bidder->id, 25000);

        $this->expectException(BidRejected::class);

        $this->action()->execute($this->item->fresh(), $this->bidder->id, 25000);
    }

    public function test_cancelled_max_bid_records_timestamp(): void
    {
        $first = $this->action()->execute($this->item, $this->bidder->id, 20000);
        $this->action()->execute($this->item->fresh(), $this->bidder->id, 30000);

        $this->assertNotNull($first->fresh()->cancelled_at);
    }

    // ── bid_type on Bid model ─────────────────────────────────────

    public function test_bid_stores_bid_type_live_by_default(): void
    {
        $bid = Bid::create([
            'auction_item_id' => $this->item->id,
            'user_id'         => $this->bidder->id,
            'amount_cents'    => 10000,
            'status'          => 'accepted',
        ]);

        $this->assertSame('live', $bid->fresh()->bid_type);
    }

    public function test_bid_stores_proxy_type_with_max_bid_reference(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        $bid = Bid::create([
            'auction_item_id' => $this->item->id,
            'user_id'         => $this->bidder->id,
            'amount_cents'    => 10000,
            'status'          => 'accepted',
            'bid_type'        => 'proxy',
            'max_bid_id'      => $maxBid->id,
        ]);

        $this->assertSame('proxy',    $bid->fresh()->bid_type);
        $this->assertSame($maxBid->id, $bid->fresh()->max_bid_id);
    }

    public function test_all_bid_types_are_accepted(): void
    {
        $types = ['live', 'pre_bid', 'proxy', 'kiosk', 'mobile', 'admin_override'];

        foreach ($types as $i => $type) {
            $bid = Bid::create([
                'auction_item_id' => $this->item->id,
                'user_id'         => User::factory()->create()->id,
                'amount_cents'    => 10000 + $i,
                'status'          => 'pending',
                'bid_type'        => $type,
            ]);

            $this->assertSame($type, $bid->fresh()->bid_type);
        }
    }

    // ── Relationships ─────────────────────────────────────────────

    public function test_max_bid_belongs_to_auction_item(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        $this->assertSame($this->item->id, $maxBid->auctionItem->id);
    }

    public function test_max_bid_belongs_to_bidder(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        $this->assertSame($this->bidder->id, $maxBid->bidder->id);
    }

    public function test_bid_belongs_to_max_bid(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        $bid = Bid::create([
            'auction_item_id' => $this->item->id,
            'user_id'         => $this->bidder->id,
            'amount_cents'    => 10000,
            'status'          => 'accepted',
            'bid_type'        => 'proxy',
            'max_bid_id'      => $maxBid->id,
        ]);

        $this->assertSame($maxBid->id, $bid->maxBid->id);
    }

    public function test_max_bid_has_many_proxy_bids(): void
    {
        $maxBid = $this->action()->execute($this->item, $this->bidder->id, 50000);

        Bid::create(['auction_item_id' => $this->item->id, 'user_id' => $this->bidder->id, 'amount_cents' => 10000, 'status' => 'outbid',   'bid_type' => 'proxy', 'max_bid_id' => $maxBid->id]);
        Bid::create(['auction_item_id' => $this->item->id, 'user_id' => $this->bidder->id, 'amount_cents' => 12000, 'status' => 'accepted', 'bid_type' => 'proxy', 'max_bid_id' => $maxBid->id]);

        $this->assertCount(2, $maxBid->proxyBids);
    }

    // ── Multiple bidders ──────────────────────────────────────────

    public function test_two_bidders_can_each_hold_an_active_max_bid(): void
    {
        $other = User::factory()->create();

        $maxBid1 = $this->action()->execute($this->item, $this->bidder->id, 20000);
        $maxBid2 = $this->action()->execute($this->item, $other->id, 25000);

        $this->assertSame('active', $maxBid1->fresh()->status);
        $this->assertSame('active', $maxBid2->fresh()->status);

        $this->assertSame(2, MaxBid::where('auction_item_id', $this->item->id)
            ->where('status', 'active')
            ->count());
    }
}
