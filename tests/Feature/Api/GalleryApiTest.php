<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for gallery endpoints:
 *   GET   /api/v1/galleries
 *   POST  /api/v1/galleries
 *   GET   /api/v1/galleries/{gallery}
 *   PATCH /api/v1/galleries/{gallery}
 *   GET   /api/v1/galleries/{gallery}/profile
 */
final class GalleryApiTest extends TestCase
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

    private function gallery(string $status = 'active'): Gallery
    {
        return Gallery::create([
            'name'   => 'Gallery ' . uniqid(),
            'slug'   => 'gallery-' . uniqid(),
            'status' => $status,
            'type'   => 'private',
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_galleries_index_is_public(): void
    {
        $this->getJson('/api/v1/galleries')->assertOk();
    }

    public function test_index_returns_galleries(): void
    {
        $this->gallery();
        $this->gallery();

        $response = $this->getJson('/api/v1/galleries')->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $this->gallery('active');
        $this->gallery('inactive');

        $response = $this->getJson('/api/v1/galleries?status=inactive')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_gallery(): void
    {
        $this->postJson('/api/v1/galleries', ['name' => 'Test'])->assertStatus(401);
    }

    public function test_buyer_cannot_create_gallery(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/galleries', ['name' => 'Test'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_gallery(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/galleries', [
                'name' => 'New Gallery',
                'type' => 'public',
            ])
            ->assertStatus(201);

        $this->assertSame('New Gallery', $response->json('name'));
        $this->assertSame('active', $response->json('status'));
    }

    public function test_store_validates_required_name(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/galleries', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_type_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/galleries', [
                'name' => 'Gallery',
                'type' => 'unknown_type',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_validates_website_url_format(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/galleries', [
                'name'    => 'Gallery',
                'website' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['website']);
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_is_public(): void
    {
        $gallery = $this->gallery();

        $this->getJson("/api/v1/galleries/{$gallery->id}")->assertOk();
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->getJson('/api/v1/galleries/99999999')->assertStatus(404);
    }

    // ── profile ───────────────────────────────────────────────────────────────

    public function test_gallery_profile_is_public(): void
    {
        $gallery = $this->gallery();

        $response = $this->getJson("/api/v1/galleries/{$gallery->id}/profile")
            ->assertOk();

        $this->assertArrayHasKey('gallery', $response->json());
        $this->assertArrayHasKey('active_lots', $response->json());
        $this->assertArrayHasKey('exhibitions', $response->json());
        $this->assertArrayHasKey('staff', $response->json());
    }
}
