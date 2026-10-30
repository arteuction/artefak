<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtmetroRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for ArtMetro admin endpoints:
 *   POST   /api/v1/admin/artmetro/routes
 *   PATCH  /api/v1/admin/artmetro/routes/{route}
 *   PATCH  /api/v1/admin/artmetro/routes/{route}/publish
 *   POST   /api/v1/admin/artmetro/routes/{route}/stops
 *   DELETE /api/v1/admin/artmetro/routes/{route}/stops/{stop}
 */
final class ArtmetroAdminApiTest extends TestCase
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

    private function route(): ArtmetroRoute
    {
        return ArtmetroRoute::create([
            'title'        => 'Test Route ' . uniqid(),
            'slug'         => 'route-' . uniqid(),
            'is_published' => false,
        ]);
    }

    private function venue(): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('venues')->insertGetId([
            'name'       => 'Test Venue ' . uniqid(),
            'slug'       => 'venue-' . uniqid(),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/artmetro/routes', ['title' => 'X'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_create_route(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/admin/artmetro/routes', ['title' => 'X'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_route(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/artmetro/routes', [
                'title'      => 'Heritage Trail',
                'difficulty' => 'moderate',
                'accessible' => true,
            ])
            ->assertStatus(201);

        $this->assertSame('Heritage Trail', $response->json('title'));
        $this->assertFalse($response->json('is_published'));
    }

    public function test_store_validates_title_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/artmetro/routes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_store_validates_difficulty_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/artmetro/routes', [
                'title'      => 'X',
                'difficulty' => 'extreme',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['difficulty']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_route(): void
    {
        $route = $this->route();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/artmetro/routes/{$route->id}", [
                'title'      => 'Updated Trail',
                'difficulty' => 'challenging',
            ])
            ->assertOk();

        $this->assertSame('Updated Trail', $response->json('title'));
    }

    // ── togglePublish ─────────────────────────────────────────────────────────

    public function test_admin_can_toggle_publish(): void
    {
        $route = $this->route();
        $this->assertFalse($route->is_published);

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/artmetro/routes/{$route->id}/publish")
            ->assertOk();

        $this->assertTrue($response->json('is_published'));

        // Toggle back
        $response2 = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/artmetro/routes/{$route->id}/publish")
            ->assertOk();

        $this->assertFalse($response2->json('is_published'));
    }

    // ── stops ─────────────────────────────────────────────────────────────────

    public function test_admin_can_add_stop_to_route(): void
    {
        $route   = $this->route();
        $venueId = $this->venue();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/artmetro/routes/{$route->id}/stops", [
                'venue_id'   => $venueId,
                'sort_order' => 1,
                'notes'      => 'First stop on the trail.',
            ])
            ->assertStatus(201);

        $this->assertSame($venueId, $response->json('venue_id'));
        $this->assertSame(1, $response->json('sort_order'));
    }

    public function test_add_stop_validates_venue_exists(): void
    {
        $route = $this->route();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/artmetro/routes/{$route->id}/stops", [
                'venue_id'   => 99999,
                'sort_order' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['venue_id']);
    }

    public function test_admin_can_remove_stop(): void
    {
        $route   = $this->route();
        $venueId = $this->venue();

        $stopResponse = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/artmetro/routes/{$route->id}/stops", [
                'venue_id'   => $venueId,
                'sort_order' => 1,
            ])
            ->assertStatus(201);

        $stopId = $stopResponse->json('id');

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/artmetro/routes/{$route->id}/stops/{$stopId}")
            ->assertStatus(204);
    }
}
