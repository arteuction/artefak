<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtworkEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 35: Artwork evidence, Venue creation, Exhibition creation.
 */
final class Phase35ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeArtwork(): array
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Phase35 Art ' . uniqid(),
            'slug'    => 'phase35-art-' . uniqid(),
            'status'  => 'listed',
        ]);
        return [$owner, $artwork];
    }

    private function makeLocality(): int
    {
        $regionId = DB::table('geo_regions')->insertGetId([
            'name' => 'R' . uniqid(), 'slug' => 'r-' . uniqid(), 'code' => 'R' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $municipalityId = DB::table('geo_municipalities')->insertGetId([
            'geo_region_id' => $regionId, 'name' => 'M' . uniqid(),
            'slug' => 'm-' . uniqid(), 'code' => 'M' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('geo_localities')->insertGetId([
            'geo_municipality_id' => $municipalityId, 'name' => 'L' . uniqid(),
            'slug' => 'l-' . uniqid(), 'ekatte' => 'E' . rand(10000, 99999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Artwork evidence ─────────────────────────────────────────────────────

    public function test_owner_can_add_artwork_evidence(): void
    {
        [$owner, $artwork] = $this->makeArtwork();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/artworks/{$artwork->slug}/evidence", [
                'type'   => 'authenticity',
                'issuer' => 'National Gallery',
            ])
            ->assertCreated()
            ->assertJsonFragment(['type' => 'authenticity', 'verification_status' => 'pending']);
    }

    public function test_public_sees_only_verified_artwork_evidence(): void
    {
        [$owner, $artwork] = $this->makeArtwork();

        // visibility='public' required for public endpoint (Phase 57: evidence visibility enforcement)
        ArtworkEvidence::create(['artwork_id' => $artwork->id, 'type' => 'provenance', 'verification_status' => 'verified', 'visibility' => 'public']);
        ArtworkEvidence::create(['artwork_id' => $artwork->id, 'type' => 'condition',  'verification_status' => 'pending',  'visibility' => 'restricted']);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/evidence")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_sees_all_artwork_evidence(): void
    {
        [$owner, $artwork] = $this->makeArtwork();
        $admin = User::factory()->create(['role' => 'admin']);

        ArtworkEvidence::create(['artwork_id' => $artwork->id, 'type' => 'provenance', 'verification_status' => 'verified']);
        ArtworkEvidence::create(['artwork_id' => $artwork->id, 'type' => 'condition',  'verification_status' => 'pending']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/artworks/{$artwork->slug}/evidence")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Venue creation ────────────────────────────────────────────────────────

    public function test_admin_can_create_venue(): void
    {
        $admin      = User::factory()->create(['role' => 'admin']);
        $localityId = $this->makeLocality();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/venues', [
                'name'            => 'New Venue',
                'geo_locality_id' => $localityId,
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'New Venue']);
    }

    public function test_non_admin_cannot_create_venue(): void
    {
        $artist     = User::factory()->create(['role' => 'artist']);
        $localityId = $this->makeLocality();

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/v1/venues', [
                'name'            => 'Sneaky Venue',
                'geo_locality_id' => $localityId,
            ])
            ->assertForbidden();
    }

    // ── Exhibition creation ──────────────────────────────────────────────────

    public function test_admin_can_create_exhibition(): void
    {
        $admin      = User::factory()->create(['role' => 'admin']);
        $localityId = $this->makeLocality();
        $venueId    = DB::table('venues')->insertGetId([
            'name' => 'Ex Venue', 'slug' => 'ex-venue-' . uniqid(),
            'geo_locality_id' => $localityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/exhibitions', [
                'title'     => 'New Exhibition',
                'venue_id'  => $venueId,
                'starts_at' => now()->addDay()->toISOString(),
                'ends_at'   => now()->addDays(7)->toISOString(),
            ])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'New Exhibition']);
    }
}
