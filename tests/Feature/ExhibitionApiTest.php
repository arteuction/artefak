<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exhibition;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for /api/v1/exhibitions — public reads, gallery-staff/admin writes.
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

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function venue(): Venue
    {
        return Venue::create([
            'name'      => 'Venue ' . uniqid(),
            'slug'      => 'venue-' . uniqid(),
            'type'      => 'gallery',
            'is_active' => true,
        ]);
    }

    private function gallery(Venue $venue): Gallery
    {
        return Gallery::create([
            'name'     => 'Gallery ' . uniqid(),
            'slug'     => 'gallery-' . uniqid(),
            'venue_id' => $venue->id,
            'status'   => 'active',
            'type'     => 'private',
        ]);
    }

    private function makeGalleryStaff(User $user, Gallery $gallery, string $role = 'staff'): GalleryStaff
    {
        return GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'status'      => 'active',
            'accepted_at' => now(),
        ]);
    }

    private function exhibition(Venue $venue): Exhibition
    {
        return Exhibition::create([
            'title'     => 'Expo ' . uniqid(),
            'slug'      => 'expo-' . uniqid(),
            'venue_id'  => $venue->id,
            'starts_at' => now()->addDay(),
            'ends_at'   => now()->addWeek(),
            'is_active' => true,
        ]);
    }

    // ── index / show ─────────────────────────────────────────────────────────

    public function test_anyone_can_list_exhibitions(): void
    {
        $venue = $this->venue();
        $this->exhibition($venue);

        $this->getJson('/api/v1/exhibitions')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_anyone_can_view_exhibition(): void
    {
        $venue      = $this->venue();
        $exhibition = $this->exhibition($venue);

        $this->getJson("/api/v1/exhibitions/{$exhibition->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $exhibition->id);
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_exhibition(): void
    {
        $venue = $this->venue();

        $this->postJson('/api/v1/exhibitions', [
            'title'    => 'Test',
            'venue_id' => $venue->id,
            'starts_at' => now()->addDay()->toDateString(),
            'ends_at'  => now()->addWeek()->toDateString(),
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_create_exhibition(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->buyer())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Test',
                'venue_id' => $venue->id,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])->assertStatus(403);
    }

    public function test_artist_without_gallery_staff_role_cannot_create_exhibition(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->artist())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Test',
                'venue_id' => $venue->id,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])->assertStatus(403);
    }

    public function test_admin_can_create_exhibition(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'New Exhibition',
                'venue_id' => $venue->id,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('title', 'New Exhibition');
    }

    public function test_gallery_staff_for_same_venue_can_create_exhibition(): void
    {
        $venue   = $this->venue();
        $gallery = $this->gallery($venue);
        $staff   = $this->artist();
        $this->makeGalleryStaff($staff, $gallery);

        $this->actingAs($staff)
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Staff Exhibition',
                'venue_id' => $venue->id,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(201);
    }

    public function test_gallery_staff_for_different_venue_cannot_create_exhibition(): void
    {
        $venue1   = $this->venue();
        $venue2   = $this->venue();
        $gallery1 = $this->gallery($venue1);
        $staff    = $this->artist();
        $this->makeGalleryStaff($staff, $gallery1);

        $this->actingAs($staff)
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Wrong Venue',
                'venue_id' => $venue2->id,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_store_validates_ends_at_after_starts_at(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Bad Dates',
                'venue_id' => $venue->id,
                'starts_at' => now()->addWeek()->toDateString(),
                'ends_at'  => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_store_requires_existing_venue_id(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/exhibitions', [
                'title'    => 'Test',
                'venue_id' => 99999,
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at'  => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['venue_id']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_exhibition(): void
    {
        $venue      = $this->venue();
        $exhibition = $this->exhibition($venue);

        $this->patchJson("/api/v1/exhibitions/{$exhibition->id}", ['title' => 'X'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_update_exhibition(): void
    {
        $venue      = $this->venue();
        $exhibition = $this->exhibition($venue);

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/exhibitions/{$exhibition->id}", ['title' => 'X'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_exhibition(): void
    {
        $venue      = $this->venue();
        $exhibition = $this->exhibition($venue);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/exhibitions/{$exhibition->id}", [
                'title'     => 'Updated Title',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_gallery_staff_can_update_own_venue_exhibition(): void
    {
        $venue      = $this->venue();
        $gallery    = $this->gallery($venue);
        $staff      = $this->artist();
        $exhibition = $this->exhibition($venue);
        $this->makeGalleryStaff($staff, $gallery);

        $this->actingAs($staff)
            ->patchJson("/api/v1/exhibitions/{$exhibition->id}", ['title' => 'By Staff'])
            ->assertOk();
    }

    public function test_gallery_staff_cannot_update_another_venues_exhibition(): void
    {
        $venue1   = $this->venue();
        $venue2   = $this->venue();
        $gallery1 = $this->gallery($venue1);
        $staff    = $this->artist();
        $this->makeGalleryStaff($staff, $gallery1);

        $exhibition = $this->exhibition($venue2);

        $this->actingAs($staff)
            ->patchJson("/api/v1/exhibitions/{$exhibition->id}", ['title' => 'Nope'])
            ->assertStatus(403);
    }
}
