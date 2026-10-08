<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtmetroRoute;
use App\Models\ArtmetroRouteStop;
use App\Models\Collection;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 37: Collection PATCH/DELETE, ArtMetro route admin CRUD.
 */
final class Phase37ApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeVenue(): Venue
    {
        $localityId = $this->makeLocality();
        return Venue::create([
            'name'            => 'V' . uniqid(),
            'slug'            => 'v-' . uniqid(),
            'geo_locality_id' => $localityId,
            'type'            => 'private',
        ]);
    }

    private function makeCollection(User $owner): Collection
    {
        return Collection::create([
            'owner_id'   => $owner->id,
            'title'      => 'Col ' . uniqid(),
            'slug'       => 'col-' . uniqid(),
            'visibility' => 'private',
        ]);
    }

    // ── Collection PATCH / DELETE ────────────────────────────────────────────

    public function test_owner_can_update_collection(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $col  = $this->makeCollection($user);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/collections/{$col->id}", [
                'title'      => 'New Title',
                'visibility' => 'public',
            ])
            ->assertOk()
            ->assertJsonFragment(['title' => 'New Title', 'visibility' => 'public']);
    }

    public function test_non_owner_cannot_update_collection(): void
    {
        $owner    = User::factory()->create(['role' => 'buyer']);
        $intruder = User::factory()->create(['role' => 'buyer']);
        $col      = $this->makeCollection($owner);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/v1/collections/{$col->id}", ['title' => 'Hack'])
            ->assertForbidden();
    }

    public function test_owner_can_delete_collection(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $col  = $this->makeCollection($user);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/collections/{$col->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('collections', ['id' => $col->id]);
    }

    public function test_non_owner_cannot_delete_collection(): void
    {
        $owner    = User::factory()->create(['role' => 'buyer']);
        $intruder = User::factory()->create(['role' => 'buyer']);
        $col      = $this->makeCollection($owner);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/v1/collections/{$col->id}")
            ->assertForbidden();
    }

    // ── ArtMetro admin CRUD ──────────────────────────────────────────────────

    public function test_admin_can_create_artmetro_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/artmetro/routes', [
                'title'      => 'Sofia Art Walk',
                'difficulty' => 'easy',
            ])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'Sofia Art Walk', 'is_published' => false]);
    }

    public function test_non_admin_cannot_create_artmetro_route(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/v1/admin/artmetro/routes', ['title' => 'Hack Route'])
            ->assertForbidden();
    }

    public function test_admin_can_toggle_publish_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $route = ArtmetroRoute::create([
            'title' => 'Draft Route', 'slug' => 'draft-route-' . uniqid(),
            'is_published' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/artmetro/routes/{$route->id}/publish")
            ->assertOk()
            ->assertJsonPath('is_published', true);
    }

    public function test_admin_can_add_and_remove_stop(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $venue = $this->makeVenue();
        $route = ArtmetroRoute::create([
            'title' => 'Route With Stop', 'slug' => 'route-ws-' . uniqid(),
            'is_published' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/artmetro/routes/{$route->id}/stops", [
                'venue_id'   => $venue->id,
                'sort_order' => 1,
                'notes'      => 'Entry point',
            ])
            ->assertCreated();

        $stopId = $response->json('id');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/artmetro/routes/{$route->id}/stops/{$stopId}")
            ->assertNoContent();
    }
}
