<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\Exhibition;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ImpactRecordApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprovedClaim(): ArtworkSdgClaim
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);
        return ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 4,
            'rationale'  => 'Education impact',
            'status'     => 'approved',
        ]);
    }

    private function makeVenueAndGallery(User $owner): array
    {
        $regionId = DB::table('geo_regions')->insertGetId([
            'name' => 'Test Region',
            'slug' => 'test-region-' . uniqid(),
            'code' => 'TR' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $municipalityId = DB::table('geo_municipalities')->insertGetId([
            'geo_region_id' => $regionId,
            'name' => 'Test Municipality',
            'slug' => 'test-muni-' . uniqid(),
            'code' => 'TM' . rand(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $localityId = DB::table('geo_localities')->insertGetId([
            'geo_municipality_id' => $municipalityId,
            'name'   => 'Test City',
            'slug'   => 'test-city-' . uniqid(),
            'ekatte' => 'E' . rand(10000, 99999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $venue = Venue::create([
            'name'            => 'Test Venue ' . uniqid(),
            'slug'            => 'test-venue-' . uniqid(),
            'geo_locality_id' => $localityId,
            'manager_id'      => $owner->id,
        ]);

        $gallery = Gallery::create([
            'name'     => 'Test Gallery ' . uniqid(),
            'slug'     => 'test-gallery-' . uniqid(),
            'owner_id' => $owner->id,
            'venue_id' => $venue->id,
            'status'   => 'active',
        ]);

        return [$venue, $gallery];
    }

    public function test_admin_can_record_impact_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $claim = $this->makeApprovedClaim();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/sdg-claims/{$claim->id}/impact-events", [
                'metric'          => 'audience_reach',
                'magnitude'       => 500,
                'idempotency_key' => 'test-impact-1',
                'note'            => 'Exhibition opening night.',
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['sdg_number' => 4]);
    }

    public function test_cannot_record_impact_against_pending_claim(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Pending Art',
            'slug'    => 'pending-art-' . uniqid(),
            'status'  => 'draft',
        ]);
        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 5,
            'rationale'  => 'Gender equality impact',
            'status'     => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/sdg-claims/{$claim->id}/impact-events", [
                'metric'          => 'audience_reach',
                'magnitude'       => 100,
                'idempotency_key' => 'test-impact-2',
            ])
            ->assertStatus(422);
    }

    public function test_gallery_staff_can_tag_exhibition_sdgs(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'artist']);

        [$venue, $gallery] = $this->makeVenueAndGallery($owner);

        GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $staff->id,
            'role'        => 'curator',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        $exhibition = Exhibition::create([
            'venue_id'  => $venue->id,
            'title'     => 'Test Exhibition',
            'slug'      => 'test-exhibition-' . uniqid(),
            'starts_at' => now()->addDay(),
            'ends_at'   => now()->addDays(7),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
                'sdg_numbers' => [4, 10, 17],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_non_staff_cannot_tag_exhibition_sdgs(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'artist']);

        [$venue] = $this->makeVenueAndGallery($owner);

        $exhibition = Exhibition::create([
            'venue_id'  => $venue->id,
            'title'     => 'Restricted Exhibition',
            'slug'      => 'restricted-ex-' . uniqid(),
            'starts_at' => now()->addDay(),
            'ends_at'   => now()->addDays(7),
        ]);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
                'sdg_numbers' => [1],
            ])
            ->assertForbidden();
    }
}
