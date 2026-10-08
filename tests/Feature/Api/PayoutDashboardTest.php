<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PayoutDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private User    $financeUser;
    private Gallery $gallery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist      = User::factory()->create(['role' => 'artist']);
        $this->financeUser = User::factory()->create();
        $this->gallery     = Gallery::create(['name' => 'Payout Gallery', 'slug' => 'payout-gallery', 'status' => 'active']);

        // Give finance user the finance role
        GalleryStaff::create([
            'gallery_id' => $this->gallery->id,
            'user_id'    => $this->financeUser->id,
            'role'       => 'finance',
            'status'     => 'active',
        ]);
    }

    // -----------------------------------------------------------------------
    // Artist payout dashboard
    // -----------------------------------------------------------------------

    public function test_artist_payout_requires_auth(): void
    {
        $this->getJson('/api/v1/payouts/artist')->assertUnauthorized();
    }

    public function test_artist_payout_returns_zero_summary_when_no_settlements(): void
    {
        Sanctum::actingAs($this->artist);

        $response = $this->getJson('/api/v1/payouts/artist')->assertOk();

        $summary = $response->json('data.summary');
        $this->assertSame(0, $summary['allocated_cents']);
        $this->assertSame(0, $summary['transferred_cents']);
        $this->assertSame(0, $summary['pending_cents']);
        $this->assertSame(0, $summary['settlement_count']);
    }

    public function test_artist_payout_counts_own_lines(): void
    {
        $settlementId = $this->makeSettlement('pi_payout_1');

        DB::table('settlement_lines')->insert([
            'settlement_id'  => $settlementId,
            'user_id'        => $this->artist->id,
            'recipient_type' => 'artist',
            'entity_name'    => 'Test Artist',
            'entity_role'    => 'artist',
            'amount_cents'   => 4500,
            'currency'       => 'EUR',
            'weight'         => 4500,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Sanctum::actingAs($this->artist);

        $response = $this->getJson('/api/v1/payouts/artist')->assertOk();
        $summary  = $response->json('data.summary');

        $this->assertSame(4500,  $summary['allocated_cents']);
        $this->assertSame(4500,  $summary['pending_cents']);
        $this->assertSame(0,     $summary['transferred_cents']);
        $this->assertSame(1,     $summary['settlement_count']);
    }

    public function test_artist_payout_counts_transferred_separately(): void
    {
        $s1 = $this->makeSettlement('pi_payout_2');
        $s2 = $this->makeSettlement('pi_payout_3');

        DB::table('settlement_lines')->insert([
            ['settlement_id' => $s1, 'user_id' => $this->artist->id, 'recipient_type' => 'artist', 'entity_name' => 'A', 'entity_role' => 'artist', 'amount_cents' => 1000, 'currency' => 'EUR', 'weight' => 1, 'status' => 'transferred', 'created_at' => now(), 'updated_at' => now()],
            ['settlement_id' => $s2, 'user_id' => $this->artist->id, 'recipient_type' => 'artist', 'entity_name' => 'A', 'entity_role' => 'artist', 'amount_cents' => 2000, 'currency' => 'EUR', 'weight' => 1, 'status' => 'pending',     'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($this->artist);

        $response = $this->getJson('/api/v1/payouts/artist')->assertOk();
        $summary  = $response->json('data.summary');

        $this->assertSame(3000, $summary['allocated_cents']);
        $this->assertSame(1000, $summary['transferred_cents']);
        $this->assertSame(2000, $summary['pending_cents']);
        $this->assertSame(2,    $summary['settlement_count']);
    }

    public function test_artist_payout_does_not_include_other_artists_lines(): void
    {
        $other  = User::factory()->create();
        $lineId = $this->makeSettlement('pi_other_artist');

        DB::table('settlement_lines')->insert([
            'settlement_id'  => $lineId,
            'user_id'        => $other->id,
            'recipient_type' => 'artist',
            'entity_name'    => 'Other Artist',
            'entity_role'    => 'artist',
            'amount_cents'   => 9999,
            'currency'       => 'EUR',
            'weight'         => 1,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Sanctum::actingAs($this->artist);

        $response = $this->getJson('/api/v1/payouts/artist')->assertOk();
        $this->assertSame(0, $response->json('data.summary.allocated_cents'));
    }

    // -----------------------------------------------------------------------
    // Gallery payout dashboard
    // -----------------------------------------------------------------------

    public function test_gallery_payout_requires_auth(): void
    {
        $this->getJson("/api/v1/payouts/gallery/{$this->gallery->id}")->assertUnauthorized();
    }

    public function test_gallery_payout_forbidden_for_non_finance(): void
    {
        $regular = User::factory()->create();
        Sanctum::actingAs($regular);

        $this->getJson("/api/v1/payouts/gallery/{$this->gallery->id}")->assertForbidden();
    }

    public function test_gallery_payout_accessible_by_finance_role(): void
    {
        Sanctum::actingAs($this->financeUser);

        $this->getJson("/api/v1/payouts/gallery/{$this->gallery->id}")
             ->assertOk()
             ->assertJsonStructure(['data' => ['gallery', 'summary', 'lines']]);
    }

    // -----------------------------------------------------------------------
    // Public artwork sales history
    // -----------------------------------------------------------------------

    public function test_artwork_sales_history_is_public(): void
    {
        $owner   = User::factory()->create();
        $artwork = \App\Models\Artwork::create(['user_id' => $owner->id, 'title' => 'H', 'slug' => 'h-hist', 'status' => 'listed']);

        $this->getJson("/api/v1/artworks/{$artwork->id}/sales-history")->assertOk();
    }

    public function test_artwork_sales_history_returns_transfers(): void
    {
        $owner  = User::factory()->create();
        $buyer  = User::factory()->create();
        $artwork = \App\Models\Artwork::create(['user_id' => $owner->id, 'title' => 'SH', 'slug' => 'sh-hist', 'status' => 'listed']);

        $lotId = DB::table('art_lots')->insertGetId([
            'artwork_id'          => $artwork->id,
            'sale_mode'           => 'auction',
            'status'              => 'sold',
            'starting_bid_cents'  => 100000,
            'currency'            => 'EUR',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('ownership_transfers')->insert([
            'art_lot_id'           => $lotId,
            'from_user_id'         => $owner->id,
            'to_user_id'           => $buyer->id,
            'channel'              => 'auction',
            'transfer_price_cents' => 250000,
            'currency'             => 'EUR',
            'transferred_at'       => now(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->id}/sales-history")->assertOk();
        $data     = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(250000, $data[0]['transfer_price_cents']);
        $this->assertSame('auction', $data[0]['channel']);
        // Buyer/seller identity must NOT be present
        $this->assertArrayNotHasKey('from_user_id', $data[0]);
        $this->assertArrayNotHasKey('to_user_id', $data[0]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeSettlement(string $pi): int
    {
        return (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $pi,
            'stripe_event_id'          => 'evt_'.uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 4500,
            'fund_cents'               => 4500,
            'ops_cents'                => 1000,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }
}
