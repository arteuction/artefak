<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Institution;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for public Venue and Institution endpoints, plus admin write paths.
 *
 * Venue:
 *   GET    /api/v1/venues
 *   GET    /api/v1/venues/{venue}
 *   POST   /api/v1/venues
 *   PATCH  /api/v1/venues/{venue}
 *
 * Institution:
 *   GET   /api/v1/institutions
 *   GET   /api/v1/institutions/{institution}
 *   POST  /api/v1/institutions
 *   PATCH /api/v1/institutions/{institution}
 */
final class VenueAndInstitutionApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function locality(): GeoLocality
    {
        static $seq = 0;
        $seq++;
        $region       = GeoRegion::create(['name' => "Region{$seq}", 'slug' => "region-{$seq}", 'code' => "R{$seq}"]);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => "Mun{$seq}", 'slug' => "mun-{$seq}", 'code' => "M{$seq}"]);

        return GeoLocality::create([
            'geo_municipality_id' => $municipality->id,
            'name'                => "City{$seq}",
            'slug'                => "city-{$seq}",
            'ekatte'              => "6813{$seq}",
            'type'                => 'city',
        ]);
    }

    private function venue(string $type = 'private'): Venue
    {
        return Venue::create([
            'name'           => 'Venue ' . uniqid(),
            'slug'           => 'venue-' . uniqid(),
            'type'           => $type,
            'geo_locality_id' => $this->locality()->id,
            'is_active'      => true,
        ]);
    }

    private function institution(bool $isActive = true): Institution
    {
        return Institution::create([
            'name'      => 'Institution ' . uniqid(),
            'slug'      => 'inst-' . uniqid(),
            'type'      => 'museum',
            'is_active' => $isActive,
        ]);
    }

    // ── Venue index (public) ─────────────────────────────────────────────────

    public function test_venue_index_is_public(): void
    {
        $this->getJson('/api/v1/venues')->assertOk();
    }

    public function test_venue_index_returns_venues(): void
    {
        $this->venue();
        $this->venue();

        $response = $this->getJson('/api/v1/venues')->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_venue_index_can_filter_by_type(): void
    {
        $this->venue('private');
        $this->venue('state');

        $response = $this->getJson('/api/v1/venues?type=state')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ── Venue show (public) ──────────────────────────────────────────────────

    public function test_venue_show_is_public(): void
    {
        $venue = $this->venue();

        $this->getJson("/api/v1/venues/{$venue->id}")->assertOk();
    }

    public function test_venue_show_returns_404_for_unknown(): void
    {
        $this->getJson('/api/v1/venues/99999999')->assertStatus(404);
    }

    // ── Venue store (admin only) ─────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_venue(): void
    {
        $this->postJson('/api/v1/venues', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_venue(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/venues', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_venue(): void
    {
        $locality = $this->locality();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/venues', [
                'name'            => 'New Gallery',
                'geo_locality_id' => $locality->id,
                'type'            => 'private',
            ])
            ->assertStatus(201);

        $this->assertSame('New Gallery', $response->json('name'));
    }

    public function test_venue_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/venues', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'geo_locality_id']);
    }

    // ── Venue update (admin only) ────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_venue(): void
    {
        $venue = $this->venue();

        $this->patchJson("/api/v1/venues/{$venue->id}", ['name' => 'New'])->assertStatus(401);
    }

    public function test_admin_can_update_venue(): void
    {
        $venue = $this->venue();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/venues/{$venue->id}", ['name' => 'Updated Name'])
            ->assertOk();

        $this->assertSame('Updated Name', $response->json('name'));
    }

    // ── Institution index (public) ───────────────────────────────────────────

    public function test_institution_index_is_public(): void
    {
        $this->getJson('/api/v1/institutions')->assertOk();
    }

    public function test_institution_index_returns_only_active(): void
    {
        $this->institution(isActive: true);
        $this->institution(isActive: false);

        $response = $this->getJson('/api/v1/institutions')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_institution_index_can_filter_by_type(): void
    {
        $this->institution(); // museum
        Institution::create(['name' => 'Foundation ' . uniqid(), 'slug' => 'found-' . uniqid(), 'type' => 'foundation', 'is_active' => true]);

        $response = $this->getJson('/api/v1/institutions?type=foundation')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ── Institution show (public) ────────────────────────────────────────────

    public function test_institution_show_is_public(): void
    {
        $inst = $this->institution();

        $this->getJson("/api/v1/institutions/{$inst->slug}")->assertOk();
    }

    public function test_institution_show_returns_404_for_unknown(): void
    {
        $this->getJson('/api/v1/institutions/does-not-exist')->assertStatus(404);
    }

    // ── Institution store (admin only) ───────────────────────────────────────

    public function test_unauthenticated_cannot_create_institution(): void
    {
        $this->postJson('/api/v1/institutions', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_institution(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/institutions', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_institution(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/institutions', [
                'name' => 'National Museum',
                'type' => 'museum',
            ])
            ->assertStatus(201);

        $this->assertSame('National Museum', $response->json('data.name'));
    }

    public function test_institution_store_validates_type_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/institutions', [
                'name' => 'Bad Type',
                'type' => 'invalid',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ── Institution update (admin only) ──────────────────────────────────────

    public function test_unauthenticated_cannot_update_institution(): void
    {
        $inst = $this->institution();

        $this->patchJson("/api/v1/institutions/{$inst->slug}", [])->assertStatus(401);
    }

    public function test_admin_can_update_institution(): void
    {
        $inst = $this->institution();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/institutions/{$inst->slug}", ['name' => 'Renamed Institution'])
            ->assertOk();

        $this->assertSame('Renamed Institution', $response->json('data.name'));
    }
}
