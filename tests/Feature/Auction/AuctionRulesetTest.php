<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\AuctionRuleset;
use App\Models\Artwork;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionRulesetTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $this->venue  = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);
    }

    private function makeAuction(?int $rulesetId = null): Auction
    {
        static $n = 0;
        $n++;
        return Auction::create([
            'title'      => "Auction {$n}",
            'slug'       => "auction-{$n}",
            'venue_id'   => $this->venue->id,
            'ruleset_id' => $rulesetId,
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addHour(),
            'status'     => 'live',
            'currency'   => 'EUR',
        ]);
    }

    private function makeItem(Auction $auction, ?int $bidIncrementCents = null): AuctionItem
    {
        static $lot = 0;
        $lot++;
        $artist  = User::factory()->create();
        $artwork = Artwork::create(['user_id' => $artist->id, 'title' => "Art{$lot}", 'slug' => "art-{$lot}", 'status' => 'in_auction']);
        $artLot  = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'EUR',
        ]);

        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => $lot,
            'bid_increment_cents' => $bidIncrementCents,
            'status'              => 'open',
        ]);
    }

    // ── Ruleset creation ───────────────────────────────────────────

    public function test_ruleset_stores_all_fields(): void
    {
        $ruleset = AuctionRuleset::create([
            'name'                        => 'Premium Auction',
            'default_bid_increment_cents' => 2000,
            'anti_sniping_seconds'        => 180,
            'extension_seconds'           => 90,
            'proxy_bid_enabled'           => true,
            'reserve_enabled'             => true,
            'counter_offer_enabled'       => true,
            'seller_approval_required'    => false,
            'tie_policy'                  => 'last_wins',
            'max_bid_policy'              => 'enabled',
        ]);

        $this->assertDatabaseHas('auction_rulesets', [
            'id'                          => $ruleset->id,
            'name'                        => 'Premium Auction',
            'default_bid_increment_cents' => 2000,
            'anti_sniping_seconds'        => 180,
            'tie_policy'                  => 'last_wins',
            'max_bid_policy'              => 'enabled',
        ]);
    }

    public function test_ruleset_has_sensible_defaults(): void
    {
        $ruleset = AuctionRuleset::create(['name' => 'Standard']);

        $this->assertSame(1000,         $ruleset->default_bid_increment_cents);
        $this->assertSame(120,          $ruleset->anti_sniping_seconds);
        $this->assertSame(120,          $ruleset->extension_seconds);
        $this->assertFalse($ruleset->proxy_bid_enabled);
        $this->assertTrue($ruleset->reserve_enabled);
        $this->assertFalse($ruleset->counter_offer_enabled);
        $this->assertFalse($ruleset->seller_approval_required);
        $this->assertSame('first_wins', $ruleset->tie_policy);
        $this->assertSame('disabled',   $ruleset->max_bid_policy);
    }

    // ── Auction ↔ Ruleset relationship ────────────────────────────

    public function test_auction_belongs_to_ruleset(): void
    {
        $ruleset = AuctionRuleset::create(['name' => 'Standard', 'default_bid_increment_cents' => 500]);
        $auction = $this->makeAuction($ruleset->id);

        $this->assertSame($ruleset->id, $auction->fresh()->ruleset_id);
        $this->assertSame($ruleset->id, $auction->ruleset->id);
    }

    public function test_auction_ruleset_id_is_nullable(): void
    {
        $auction = $this->makeAuction(null);

        $this->assertNull($auction->ruleset_id);
        $this->assertNull($auction->ruleset);
    }

    // ── nextBidCents falls back to ruleset ────────────────────────

    public function test_next_bid_uses_item_increment_when_set(): void
    {
        $ruleset = AuctionRuleset::create(['name' => 'Standard', 'default_bid_increment_cents' => 5000]);
        $auction = $this->makeAuction($ruleset->id);
        $item    = $this->makeItem($auction, bidIncrementCents: 250); // item override wins

        // No bids yet — starts at artLot.starting_bid_cents (10000)
        $this->assertSame(10000, $item->nextBidCents());
    }

    public function test_next_bid_falls_back_to_ruleset_increment_when_item_increment_is_null(): void
    {
        $ruleset = AuctionRuleset::create(['name' => 'Standard', 'default_bid_increment_cents' => 3000]);
        $auction = $this->makeAuction($ruleset->id);
        $item    = $this->makeItem($auction, bidIncrementCents: null);

        // Place a fake accepted bid to test increment arithmetic
        \App\Models\Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 10000,
            'status'          => 'accepted',
        ]);

        // next = highest (10000) + ruleset default (3000) = 13000
        $this->assertSame(13000, $item->fresh()->nextBidCents());
    }

    public function test_next_bid_falls_back_to_hardcoded_1000_when_no_ruleset(): void
    {
        $auction = $this->makeAuction(null); // no ruleset
        $item    = $this->makeItem($auction, bidIncrementCents: null);

        \App\Models\Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => User::factory()->create()->id,
            'amount_cents'    => 10000,
            'status'          => 'accepted',
        ]);

        // next = highest (10000) + hardcoded fallback (1000) = 11000
        $this->assertSame(11000, $item->fresh()->nextBidCents());
    }

    // ── Ruleset → Auction inverse ──────────────────────────────────

    public function test_ruleset_has_many_auctions(): void
    {
        $ruleset = AuctionRuleset::create(['name' => 'Shared Ruleset', 'default_bid_increment_cents' => 1000]);

        $this->makeAuction($ruleset->id);
        $this->makeAuction($ruleset->id);

        $this->assertCount(2, $ruleset->auctions);
    }
}
