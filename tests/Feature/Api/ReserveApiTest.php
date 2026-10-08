<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\Reserve;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReserveApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeReserveFixture(): array
    {
        $seller  = User::factory()->create(['role' => 'artist']);
        $bidder  = User::factory()->create(['role' => 'artist']);

        $artwork = Artwork::create([
            'user_id' => $seller->id,
            'title'   => 'Reserve Test Art',
            'slug'    => 'reserve-art-' . uniqid(),
            'status'  => 'in_auction',
        ]);

        $artLot = ArtLot::create([
            'artwork_id'          => $artwork->id,
            'consignor_id'        => $seller->id,
            'sale_mode'           => 'auction',
            'currency'            => 'EUR',
            'reserve_price_cents' => 50000,
            'starting_bid_cents'  => 10000,
            'status'              => 'active',
        ]);

        $regionId = DB::table('geo_regions')->insertGetId([
            'name' => 'R' . uniqid(), 'slug' => 'r-' . uniqid(), 'code' => 'R' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $municipalityId = DB::table('geo_municipalities')->insertGetId([
            'geo_region_id' => $regionId, 'name' => 'M' . uniqid(),
            'slug' => 'm-' . uniqid(), 'code' => 'M' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $localityId = DB::table('geo_localities')->insertGetId([
            'geo_municipality_id' => $municipalityId, 'name' => 'L' . uniqid(),
            'slug' => 'l-' . uniqid(), 'ekatte' => 'E' . rand(10000, 99999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $venueId = DB::table('venues')->insertGetId([
            'name' => 'V' . uniqid(), 'slug' => 'v-' . uniqid(),
            'geo_locality_id' => $localityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $auction = Auction::create([
            'title'      => 'Reserve Auction ' . uniqid(),
            'slug'       => 'reserve-auction-' . uniqid(),
            'venue_id'   => $venueId,
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addHour(),
            'status'     => 'live',
        ]);

        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'starting_bid_cents'  => 10000,
            'bid_increment_cents' => 1000,
            'status'              => 'reserve_not_met',
        ]);

        $bid = Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $bidder->id,
            'amount_cents'    => 20000,
            'status'          => 'accepted',
        ]);

        $reserve = Reserve::create([
            'auction_item_id'     => $item->id,
            'reserve_price_cents' => 50000,
            'highest_bid_cents'   => 20000,
            'status'              => 'not_reached',
        ]);

        return [$seller, $bidder, $reserve, $item];
    }

    public function test_seller_can_view_reserve(): void
    {
        [$seller, , $reserve] = $this->makeReserveFixture();

        $this->actingAs($seller, 'sanctum')
            ->getJson("/api/v1/reserves/{$reserve->id}")
            ->assertOk()
            ->assertJsonFragment(['status' => 'not_reached']);
    }

    public function test_seller_can_waive_reserve(): void
    {
        [$seller, , $reserve] = $this->makeReserveFixture();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/reserves/{$reserve->id}/waive", ['notes' => 'Accept highest bid.'])
            ->assertOk()
            ->assertJsonFragment(['status' => 'waived']);
    }

    public function test_seller_can_issue_counter_offer(): void
    {
        [$seller, , $reserve] = $this->makeReserveFixture();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/reserves/{$reserve->id}/counter-offer", [
                'counter_offer_cents' => 35000,
                'expires_in_hours'    => 24,
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'counter_offered']);
    }

    public function test_non_seller_cannot_waive(): void
    {
        [, $bidder, $reserve] = $this->makeReserveFixture();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/v1/reserves/{$reserve->id}/waive")
            ->assertForbidden();
    }
}
