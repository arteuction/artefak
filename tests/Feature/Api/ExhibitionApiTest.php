<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Exhibition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for exhibition endpoints:
 *   GET   /api/v1/exhibitions
 *   GET   /api/v1/exhibitions/{exhibition}
 *   POST  /api/v1/exhibitions
 *   PATCH /api/v1/exhibitions/{exhibition}
 *   PUT   /api/v1/exhibitions/{exhibition}/sdg-tags
 */
final class ExhibitionApiTest extends TestCase
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

    private function venue(): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('venues')->insertGetId([
            'name'       => 'Venue ' . uniqid(),
            'slug'       => 'venue-' . uniqid(),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    private function exhibition(int $venueId): Exhibition
    {
        return Exhibition::create([
            'title'      => 'Exhibition ' . uniqid(),
            'slug'       => 'exhibit-' . uniqid(),
            'venue_id'   => $venueId,
            'starts_at'  => now()->addDay(),
            'ends_at'    => now()->addDays(7),
            'is_active'  => true,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_is_public(): void
    {
        $this->getJson('/api/v1/exhibitions')->assertOk();
    }

    public function test_index_returns_data_key(): void
    {
        $venueId = $this->venue();
        $this->exhibition($venueId);

        $response = $this->getJson('/api/v1/exhibitions')->assertOk();

        $this->assertArrayHasKey('data', $response->json());
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_is_public(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $this->getJson("/api/v1/exhibitions/{$exhibition->id}")->assertOk();
    }

    public function test_show_returns_data_key(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $response = $this->getJson("/api/v1/exhibitions/{$exhibition->id}")->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertSame($exhibition->id, $response->json('data.id'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $venueId = $this->venue();
        $this->postJson('/api/v1/exhibitions', [
            'title'    => 'New Exhibition',
            'venue_id' => $venueId,
            'starts_at' => now()->addDay()->toDateString(),
            'ends_at'   => now()->addDays(7)->toDateString(),
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_create_exhibition(): void
    {
        $venueId = $this->venue();
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'New Exhibition',
                'venue_id' => $venueId,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'   => now()->addDays(7)->toDateString(),
            ])->assertStatus(403);
    }

    public function test_admin_can_create_exhibition(): void
    {
        $venueId = $this->venue();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Modern Masters',
                'venue_id' => $venueId,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'   => now()->addDays(14)->toDateString(),
            ])->assertStatus(201);

        $this->assertSame('Modern Masters', $response->json('title'));
    }

    public function test_store_validates_ends_at_after_starts_at(): void
    {
        $venueId = $this->venue();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'X',
                'venue_id' => $venueId,
                'starts_at' => now()->addDays(7)->toDateString(),
                'ends_at'   => now()->addDay()->toDateString(), // before starts_at
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    // ── SDG tags ──────────────────────────────────────────────────────────────

    public function test_sdg_tag_requires_authentication(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $this->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
            'sdg_numbers' => [7, 13],
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_tag_exhibition_sdgs(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $this->actingAs($this->buyer())
            ->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
                'sdg_numbers' => [7, 13],
            ])->assertStatus(403);
    }

    public function test_admin_can_tag_exhibition_sdgs(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $response = $this->actingAs($this->admin())
            ->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
                'sdg_numbers' => [7, 13],
            ])->assertOk();

        $this->assertContains(7, $response->json('data'));
        $this->assertContains(13, $response->json('data'));
    }

    public function test_sdg_numbers_must_be_in_range(): void
    {
        $venueId    = $this->venue();
        $exhibition = $this->exhibition($venueId);

        $this->actingAs($this->admin())
            ->putJson("/api/v1/exhibitions/{$exhibition->id}/sdg-tags", [
                'sdg_numbers' => [0, 18], // out of range
            ])->assertStatus(422);
    }
}
