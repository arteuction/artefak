<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtLot;
use App\Models\ArtworkSdgClaim;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\Exhibition;
use App\Models\Gallery;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\ImpactEvent;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ApiCompleteness13ATest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Galleries
    // -----------------------------------------------------------------------

    public function test_galleries_index_is_public(): void
    {
        Gallery::create(['name' => 'Sofia Art', 'slug' => 'sofia-art', 'status' => 'active']);

        $this->getJson('/api/v1/galleries')
             ->assertOk()
             ->assertJsonPath('data.data.0.name', 'Sofia Art');
    }

    public function test_galleries_show_returns_gallery(): void
    {
        $gallery = Gallery::create(['name' => 'G', 'slug' => 'g', 'status' => 'active']);

        $this->getJson("/api/v1/galleries/{$gallery->id}")
             ->assertOk()
             ->assertJsonPath('data.id', $gallery->id);
    }

    public function test_galleries_index_filters_by_status(): void
    {
        Gallery::create(['name' => 'Active', 'slug' => 'active', 'status' => 'active']);
        Gallery::create(['name' => 'Inactive', 'slug' => 'inactive', 'status' => 'inactive']);

        $this->getJson('/api/v1/galleries?status=active')
             ->assertOk()
             ->assertJsonCount(1, 'data.data');
    }

    // -----------------------------------------------------------------------
    // Venues
    // -----------------------------------------------------------------------

    public function test_venues_index_is_public(): void
    {
        $venue = $this->makeVenue();

        $this->getJson('/api/v1/venues')
             ->assertOk()
             ->assertJsonPath('data.data.0.id', $venue->id);
    }

    public function test_venues_show_returns_venue(): void
    {
        $venue = $this->makeVenue();

        $this->getJson("/api/v1/venues/{$venue->id}")
             ->assertOk()
             ->assertJsonPath('data.id', $venue->id);
    }

    // -----------------------------------------------------------------------
    // Exhibitions
    // -----------------------------------------------------------------------

    public function test_exhibitions_index_is_public(): void
    {
        $venue = $this->makeVenue();
        Exhibition::create([
            'venue_id'  => $venue->id,
            'title'     => 'Spring Show',
            'slug'      => 'spring-show',
            'status'    => 'active',
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addDay(),
        ]);

        $this->getJson('/api/v1/exhibitions')
             ->assertOk()
             ->assertJsonPath('data.data.0.title', 'Spring Show');
    }

    public function test_exhibitions_show_returns_exhibition(): void
    {
        $venue      = $this->makeVenue();
        $exhibition = Exhibition::create([
            'venue_id'  => $venue->id,
            'title'     => 'Solo',
            'slug'      => 'solo',
            'status'    => 'active',
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addDay(),
        ]);

        $this->getJson("/api/v1/exhibitions/{$exhibition->id}")
             ->assertOk()
             ->assertJsonPath('data.id', $exhibition->id);
    }

    // -----------------------------------------------------------------------
    // Impact events (public)
    // -----------------------------------------------------------------------

    public function test_impact_events_index_is_public(): void
    {
        $this->makeImpactEvent();

        $this->getJson('/api/v1/impact-events')
             ->assertOk()
             ->assertJsonStructure(['data' => ['data']]);
    }

    public function test_impact_events_show_returns_event(): void
    {
        $event = $this->makeImpactEvent();

        $this->getJson("/api/v1/impact-events/{$event->id}")
             ->assertOk()
             ->assertJsonPath('data.id', $event->id);
    }

    // -----------------------------------------------------------------------
    // Donations (authenticated)
    // -----------------------------------------------------------------------

    public function test_donations_index_requires_auth(): void
    {
        $this->getJson('/api/v1/donations')->assertUnauthorized();
    }

    public function test_donations_index_returns_own_donations(): void
    {
        $donor = User::create(['name' => 'D', 'email' => 'd@t.com', 'password' => 'x', 'role' => 'buyer']);
        $other = User::create(['name' => 'O', 'email' => 'o@t.com', 'password' => 'x', 'role' => 'buyer']);
        $recip = $this->makeRecipient();

        Donation::create(['donation_recipient_id' => $recip->id, 'donor_id' => $donor->id, 'donated_cents' => 1000, 'currency' => 'EUR', 'eligibility_basis' => 'ZKPO_ART31_1', 'deduction_bps' => 1000, 'max_deductible_cents' => 100, 'type' => 'donation', 'status' => 'pending', 'idempotency_key' => 'dk-1']);
        Donation::create(['donation_recipient_id' => $recip->id, 'donor_id' => $other->id, 'donated_cents' => 2000, 'currency' => 'EUR', 'eligibility_basis' => 'ZKPO_ART31_1', 'deduction_bps' => 1000, 'max_deductible_cents' => 200, 'type' => 'donation', 'status' => 'pending', 'idempotency_key' => 'dk-2']);

        Sanctum::actingAs($donor);

        $this->getJson('/api/v1/donations')
             ->assertOk()
             ->assertJsonCount(1, 'data.data');
    }

    public function test_donations_admin_sees_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $donor = User::create(['name' => 'D2', 'email' => 'd2@t.com', 'password' => 'x', 'role' => 'buyer']);
        $recip = $this->makeRecipient();

        // Create the donation as the donor, then verify admin can see it
        Sanctum::actingAs($donor);
        Donation::create(['donation_recipient_id' => $recip->id, 'donor_id' => $donor->id, 'donated_cents' => 1000, 'currency' => 'EUR', 'eligibility_basis' => 'ZKPO_ART31_1', 'deduction_bps' => 1000, 'max_deductible_cents' => 100, 'type' => 'donation', 'status' => 'pending', 'idempotency_key' => 'dk-3']);
        $this->assertDatabaseCount('donations', 1);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/donations')
             ->assertOk()
             ->assertJsonCount(1, 'data.data');
    }

    // -----------------------------------------------------------------------
    // Ownership transfers (authenticated)
    // -----------------------------------------------------------------------

    public function test_ownership_transfers_index_requires_auth(): void
    {
        $this->getJson('/api/v1/ownership-transfers')->assertUnauthorized();
    }

    public function test_ownership_transfers_index_returns_own_transfers(): void
    {
        $seller = User::create(['name' => 'S', 'email' => 'sel@t.com', 'password' => 'x', 'role' => 'seller']);
        $buyer  = User::create(['name' => 'B', 'email' => 'buy@t.com', 'password' => 'x', 'role' => 'buyer']);
        $third  = User::create(['name' => 'T', 'email' => 'thi@t.com', 'password' => 'x', 'role' => 'buyer']);

        $artwork = Artwork::create(['user_id' => $seller->id, 'title' => 'A', 'slug' => 'a', 'status' => 'listed']);
        $lot     = ArtLot::create(['artwork_id' => $artwork->id, 'status' => 'sold', 'sale_mode' => 'sell_now', 'currency' => 'EUR']);

        OwnershipTransfer::create(['art_lot_id' => $lot->id, 'from_user_id' => $seller->id, 'to_user_id' => $buyer->id, 'transfer_price_cents' => 5000, 'currency' => 'EUR', 'channel' => 'sell_now', 'transferred_at' => now()]);
        OwnershipTransfer::create(['art_lot_id' => $lot->id, 'from_user_id' => $third->id,  'to_user_id' => $third->id,  'transfer_price_cents' => 1000, 'currency' => 'EUR', 'channel' => 'sell_now', 'transferred_at' => now()]);

        Sanctum::actingAs($buyer);

        $this->getJson('/api/v1/ownership-transfers')
             ->assertOk()
             ->assertJsonCount(1, 'data.data');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeVenue(): Venue
    {
        $locality = $this->makeLocality();
        return Venue::create(['name' => 'V'.uniqid(), 'slug' => 'v-'.uniqid(), 'type' => 'private', 'geo_locality_id' => $locality->id]);
    }

    private function makeLocality(): GeoLocality
    {
        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia-'.uniqid(), 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof-'.uniqid(), 'code' => 'SOF01']);
        static $ekatte = 68000;
        return GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-'.uniqid(), 'ekatte' => (string) ++$ekatte, 'type' => 'city']);
    }

    private function makeRecipient(): DonationRecipient
    {
        return DonationRecipient::create([
            'name'              => 'Org',
            'eik'               => 'BG'.rand(100000000, 999999999),
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => 'active',
        ]);
    }

    private function makeImpactEvent(): ImpactEvent
    {
        $user    = User::create(['name' => 'U', 'email' => 'u'.uniqid().'@t.com', 'password' => 'x', 'role' => 'seller']);
        $artwork = Artwork::create(['user_id' => $user->id, 'title' => 'I', 'slug' => 'i-'.uniqid(), 'status' => 'listed']);
        $lot     = ArtLot::create(['artwork_id' => $artwork->id, 'status' => 'active', 'sale_mode' => 'sell_now', 'currency' => 'EUR']);
        $claim   = ArtworkSdgClaim::create(['artwork_id' => $artwork->id, 'sdg_number' => 4, 'rationale' => 'ok', 'status' => 'approved']);

        return ImpactEvent::create([
            'artwork_sdg_claim_id' => $claim->id,
            'sdg_number'           => 4,
            'metric'               => 'audience_reach',
            'magnitude'            => 50,
            'currency'             => 'EUR',
            'type'                 => 'event',
            'idempotency_key'      => 'ie-'.uniqid(),
        ]);
    }
}
