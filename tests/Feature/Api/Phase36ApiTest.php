<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AdminAuditLog;
use App\Models\ArtistProfile;
use App\Models\Book;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 36: Book publish/unpublish, Artist profile PATCH,
 *           Gallery PATCH, Admin audit log.
 */
final class Phase36ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(string $status = 'draft'): Book
    {
        $owner = User::factory()->create(['role' => 'artist']);
        return Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Book ' . uniqid(),
            'slug'        => 'book-' . uniqid(),
            'status'      => $status,
            'is_free'     => false,
            'price_cents' => 1500,
            'currency'    => 'BGN',
            'language'    => 'bg',
        ]);
    }

    private function makeGallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'Gallery ' . uniqid(),
            'slug'   => 'gallery-' . uniqid(),
            'type'   => 'private',
            'status' => 'active',
        ]);
    }

    // ── Book publish / unpublish ─────────────────────────────────────────────

    public function test_admin_can_publish_book(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book  = $this->makeBook('draft');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/publish")
            ->assertOk()
            ->assertJsonPath('book.status', 'published');
    }

    public function test_non_admin_cannot_publish_book(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $book   = $this->makeBook('draft');

        $this->actingAs($artist, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/publish")
            ->assertForbidden();
    }

    public function test_admin_can_unpublish_book(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book  = $this->makeBook('published');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('book.status', 'draft');
    }

    public function test_unpublish_draft_book_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book  = $this->makeBook('draft');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/unpublish")
            ->assertUnprocessable();
    }

    // ── Artist profile PATCH ─────────────────────────────────────────────────

    public function test_user_can_update_own_artist_profile(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Original Name',
            'slug'         => 'original-name-' . $user->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', ['display_name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('display_name', 'Updated Name');
    }

    public function test_artist_profile_patch_404_if_no_profile(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', ['bio' => 'Hi'])
            ->assertNotFound();
    }

    // ── Gallery PATCH ─────────────────────────────────────────────────────────

    public function test_admin_can_update_gallery(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gallery = $this->makeGallery();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/galleries/{$gallery->id}", ['name' => 'Renamed Gallery'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed Gallery');
    }

    public function test_gallery_manager_can_update_gallery(): void
    {
        $manager = User::factory()->create(['role' => 'artist']);
        $gallery = $this->makeGallery();
        GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $manager->id,
            'role'        => 'manager',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/galleries/{$gallery->id}", ['website' => 'https://example.com'])
            ->assertOk()
            ->assertJsonPath('website', 'https://example.com');
    }

    public function test_non_staff_cannot_update_gallery(): void
    {
        $outsider = User::factory()->create(['role' => 'artist']);
        $gallery  = $this->makeGallery();

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/v1/galleries/{$gallery->id}", ['name' => 'Hack'])
            ->assertForbidden();
    }

    // ── Admin audit log ──────────────────────────────────────────────────────

    public function test_admin_can_list_audit_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book  = $this->makeBook();
        DB::table('admin_audit_log')->insert([
            'actor_id'     => $admin->id,
            'subject_type' => 'book',
            'subject_id'   => $book->id,
            'action'       => 'publish',
            'payload'      => null,
            'created_at'   => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-log')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_non_admin_cannot_access_audit_log(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->getJson('/api/v1/admin/audit-log')
            ->assertForbidden();
    }
}
