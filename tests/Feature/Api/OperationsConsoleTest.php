<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Donation\AccumulateDonorFiscalYear;
use App\Domain\Donation\RecordDonation;
use App\Domain\Outbox\AppendDomainEvent;
use App\Domain\Outbox\RecordConsumerEvent;
use App\Models\Artwork;
use App\Models\ArtLot;
use App\Models\AuctionItem;
use App\Models\Auction;
use App\Models\Consignment;
use App\Models\DomainEvent;
use App\Models\DonationRecipient;
use App\Models\DonorFiscalYear;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Reserve;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 13-H — Operations Console tests.
 */
final class OperationsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = User::factory()->create(['role' => 'admin']);
        $this->regular = User::factory()->create(['role' => 'buyer']);
    }

    // -----------------------------------------------------------------------
    // Auth guard
    // -----------------------------------------------------------------------

    public function test_ops_summary_requires_admin(): void
    {
        $this->getJson('/api/v1/ops/summary')->assertUnauthorized();
    }

    public function test_ops_summary_forbidden_for_non_admin(): void
    {
        Sanctum::actingAs($this->regular);
        $this->getJson('/api/v1/ops/summary')->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Summary endpoint
    // -----------------------------------------------------------------------

    public function test_ops_summary_returns_expected_keys(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/ops/summary')
             ->assertOk()
             ->assertJsonStructure([
                 'data' => [
                     'pending_domain_events',
                     'failed_outbox',
                     'open_reserves',
                     'active_consignments',
                     'fy_incomplete_donors',
                 ],
             ]);
    }

    public function test_ops_summary_counts_pending_events(): void
    {
        // Create one domain event — not consumed
        $artwork = $this->makeArtwork();
        (new AppendDomainEvent())->execute(
            aggregate:      $artwork,
            eventType:      'test.event',
            payload:        ['x' => 1],
            idempotencyKey: 'test-event-ops-1',
        );

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/ops/summary')->assertOk();
        $this->assertSame(1, $response->json('data.pending_domain_events'));
    }

    public function test_ops_summary_pending_events_decreases_after_consumption(): void
    {
        $artwork = $this->makeArtwork();
        (new AppendDomainEvent())->execute(
            aggregate:      $artwork,
            eventType:      'test.event',
            payload:        ['x' => 2],
            idempotencyKey: 'test-event-ops-2',
        );

        $event = DomainEvent::first();
        (new RecordConsumerEvent())->execute('email_worker', $event);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/ops/summary')->assertOk();
        $this->assertSame(0, $response->json('data.pending_domain_events'));
    }

    public function test_ops_summary_counts_open_reserves(): void
    {
        $item = $this->makeAuctionItem();
        Reserve::create([
            'auction_item_id'    => $item->id,
            'reserve_price_cents'=> 50000,
            'highest_bid_cents'  => 10000,
            'status'             => 'not_reached',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/ops/summary')->assertOk();
        $this->assertSame(1, $response->json('data.open_reserves'));
    }

    public function test_ops_summary_counts_active_consignments(): void
    {
        $artwork = $this->makeArtwork();
        $gallery = \App\Models\Gallery::create(['name' => 'G', 'slug' => 'g-ops', 'status' => 'active']);
        Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $artwork->user_id,
            'consignor_id'   => $artwork->user_id,
            'gallery_id'     => $gallery->id,
            'commission_bps' => 1500,
            'status'         => 'active',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/ops/summary')->assertOk();
        $this->assertSame(1, $response->json('data.active_consignments'));
    }

    public function test_ops_summary_counts_fy_incomplete_donors(): void
    {
        $donor    = User::factory()->create();
        $recip    = $this->makeRecipient();
        $donation = (new RecordDonation())->execute($recip, $donor, 10000, 'dk-ops-fy-1');
        (new AccumulateDonorFiscalYear())->execute($donation);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/ops/summary')->assertOk();
        $this->assertSame(1, $response->json('data.fy_incomplete_donors'));
    }

    // -----------------------------------------------------------------------
    // Individual list endpoints
    // -----------------------------------------------------------------------

    public function test_ops_pending_events_returns_paginated_events(): void
    {
        $artwork = $this->makeArtwork();
        (new AppendDomainEvent())->execute(
            aggregate:      $artwork,
            eventType:      'artwork.listed',
            payload:        ['id' => $artwork->id],
            idempotencyKey: 'test-pending-list-1',
        );

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/ops/pending-events')
             ->assertOk()
             ->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function test_ops_open_reserves_returns_paginated_reserves(): void
    {
        $item = $this->makeAuctionItem();
        Reserve::create([
            'auction_item_id'    => $item->id,
            'reserve_price_cents'=> 50000,
            'highest_bid_cents'  => 10000,
            'status'             => 'not_reached',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/ops/open-reserves')
             ->assertOk()
             ->assertJsonPath('data.total', 1);
    }

    public function test_ops_active_consignments_endpoint(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/ops/active-consignments')
             ->assertOk()
             ->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function test_ops_fiscal_year_endpoint_with_year(): void
    {
        $donor    = User::factory()->create();
        $recip    = $this->makeRecipient();
        $donation = (new RecordDonation())->execute($recip, $donor, 10000, 'dk-ops-fy-yr-1');
        (new AccumulateDonorFiscalYear())->execute($donation);

        Sanctum::actingAs($this->admin);
        $year = (int) now()->format('Y');

        $this->getJson("/api/v1/ops/fiscal-year/{$year}")
             ->assertOk()
             ->assertJsonPath('data.total', 1);
    }

    public function test_ops_failed_outbox_endpoint(): void
    {
        // Create minimum parent rows to satisfy FK chain
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_ops_test',
            'stripe_event_id'          => 'evt_ops_test',
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
            'status'                   => 'pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id'   => $settlementId,
            'recipient_type'  => 'artist',
            'entity_name'     => 'Test Artist',
            'entity_role'     => 'artist',
            'amount_cents'    => 4500,
            'currency'        => 'EUR',
            'weight'          => 4500,
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('transfer_outbox')->insert([
            'settlement_line_id'     => $lineId,
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500,
            'currency'               => 'EUR',
            'stripe_idempotency_key' => 'pi_ops_test_'.$lineId,
            'status'                 => 'failed',
            'attempt'                => 3,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/ops/failed-outbox')
             ->assertOk()
             ->assertJsonPath('data.total', 1);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeArtwork(): Artwork
    {
        $user = User::factory()->create();
        return Artwork::create([
            'user_id' => $user->id,
            'title'   => 'Ops Art '.uniqid(),
            'slug'    => 'ops-art-'.uniqid(),
            'status'  => 'listed',
        ]);
    }

    private function makeAuctionItem(): AuctionItem
    {
        $region   = GeoRegion::create(['name' => 'R', 'slug' => 'r-'.uniqid(), 'code' => 'RR1']);
        $muni     = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'M', 'slug' => 'm-'.uniqid(), 'code' => 'MM1']);
        $locality = GeoLocality::create(['geo_municipality_id' => $muni->id, 'name' => 'L', 'slug' => 'l-'.uniqid(), 'ekatte' => (string) rand(10000, 99999), 'type' => 'city']);
        $venue    = Venue::create(['name' => 'V', 'slug' => 'v-'.uniqid(), 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'Ops Auction',
            'slug'      => 'ops-auction-'.uniqid(),
            'venue_id'  => $venue->id,
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artwork = $this->makeArtwork();
        $lot     = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'EUR',
        ]);

        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);
    }

    private function makeRecipient(): DonationRecipient
    {
        return DonationRecipient::create([
            'name'              => 'Ops NGO',
            'eik'               => 'BG'.rand(100000000, 999999999),
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => 'active',
        ]);
    }
}
