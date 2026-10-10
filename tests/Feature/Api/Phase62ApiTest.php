<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\Artwork;
use App\Models\ArtworkEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 62: Artist self-management — profile PATCH, artwork store/update,
 *           and artwork evidence submission.
 */
final class Phase62ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Artist profile PATCH ──────────────────────────────────────────────────

    private function makeArtistWithProfile(): array
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'artist']);
        $user = $user->fresh();

        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Original Name',
            'slug'         => 'original-name-' . uniqid(),
            'bio'          => 'Original bio.',
            'status'       => 'approved',
        ]);

        return [$user, $profile];
    }

    public function test_artist_can_update_own_profile(): void
    {
        [$user, $profile] = $this->makeArtistWithProfile();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', [
                'display_name' => 'Updated Name',
                'bio'          => 'A new biography.',
                'website'      => 'https://mysite.example.com',
            ])
            ->assertOk();

        $this->assertSame('Updated Name', $response->json('display_name'));
        $this->assertSame('A new biography.', $response->json('bio'));
        $this->assertSame('https://mysite.example.com', $response->json('website'));

        $this->assertDatabaseHas('artist_profiles', [
            'id'           => $profile->id,
            'display_name' => 'Updated Name',
        ]);
    }

    public function test_profile_update_validates_website_url(): void
    {
        [$user,] = $this->makeArtistWithProfile();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', [
                'website' => 'not-a-url',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website']);
    }

    public function test_profile_update_returns_404_when_no_profile_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', [
                'display_name' => 'New Name',
            ])
            ->assertNotFound();
    }

    public function test_partial_profile_update_preserves_untouched_fields(): void
    {
        [$user, $profile] = $this->makeArtistWithProfile();

        // Update only bio — display_name should remain unchanged
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/artist-profile', [
                'bio' => 'Only bio changed.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('artist_profiles', [
            'id'           => $profile->id,
            'display_name' => 'Original Name',
            'bio'          => 'Only bio changed.',
        ]);
    }

    // ── Artwork store ─────────────────────────────────────────────────────────

    public function test_authenticated_user_can_create_artwork(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artworks', [
                'title'        => 'New Painting',
                'slug'         => 'new-painting-' . uniqid(),
                'medium'       => 'painting',
                'year_created' => 2025,
                'description'  => 'A beautiful painting.',
                'is_original'  => true,
            ])
            ->assertCreated();

        $this->assertSame('New Painting', $response->json('title'));
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame($user->id, $response->json('user_id'));
    }

    public function test_artwork_store_validates_required_fields(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artworks', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'slug']);
    }

    public function test_artwork_slug_must_be_unique(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        Artwork::create([
            'user_id' => $user->id,
            'title'   => 'First',
            'slug'    => 'slug-taken',
            'status'  => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artworks', [
                'title' => 'Second',
                'slug'  => 'slug-taken',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    // ── Artwork PATCH ─────────────────────────────────────────────────────────

    public function test_artist_can_update_own_artwork(): void
    {
        $user    = User::factory()->create();
        $artwork = Artwork::create([
            'user_id'      => $user->id,
            'title'        => 'Old Title',
            'slug'         => 'old-title-' . uniqid(),
            'status'       => 'draft',
            'medium'       => 'painting',
            'year_created' => 2020,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", [
                'title'        => 'New Title',
                'year_created' => 2024,
            ])
            ->assertOk();

        $this->assertSame('New Title', $response->json('title'));
        $this->assertSame(2024, $response->json('year_created'));
    }

    public function test_artist_cannot_update_another_artists_artwork(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Owned Artwork',
            'slug'    => 'owned-artwork-' . uniqid(),
            'status'  => 'draft',
        ]);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", [
                'title' => 'Hijacked Title',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_any_artwork(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $admin  = User::factory()->create(['role' => 'admin']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Artist Artwork',
            'slug'    => 'artist-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", [
                'title' => 'Admin Fixed Title',
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Admin Fixed Title');
    }

    // ── Artwork evidence submission ───────────────────────────────────────────

    public function test_artwork_owner_can_submit_evidence(): void
    {
        $user    = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $user->id,
            'title'   => 'Evidenced Work',
            'slug'    => 'evidenced-' . uniqid(),
            'status'  => 'listed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/artworks/{$artwork->slug}/evidence", [
                'type'          => 'authenticity',
                'issuer'        => 'National Gallery',
                'issued_at'     => '2024-06-01',
                'document_path' => 'uploads/cert.pdf',
                'notes'         => 'Certificate of authenticity.',
            ])
            ->assertCreated();

        $this->assertSame('authenticity', $response->json('type'));
        $this->assertSame('pending', $response->json('verification_status'));
        $this->assertSame($artwork->id, $response->json('artwork_id'));
    }

    public function test_non_owner_cannot_submit_evidence(): void
    {
        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Protected Work',
            'slug'    => 'protected-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/artworks/{$artwork->slug}/evidence", [
                'type' => 'provenance',
            ])
            ->assertForbidden();
    }

    public function test_evidence_submission_validates_type_enum(): void
    {
        $user    = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $user->id,
            'title'   => 'Test',
            'slug'    => 'test-enum-' . uniqid(),
            'status'  => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/artworks/{$artwork->slug}/evidence", [
                'type' => 'invalid_type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_admin_can_submit_evidence_for_any_artwork(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Admin Evidenced',
            'slug'    => 'admin-evidenced-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/artworks/{$artwork->slug}/evidence", [
                'type'  => 'condition',
                'notes' => 'Condition report completed.',
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'condition');
    }
}
