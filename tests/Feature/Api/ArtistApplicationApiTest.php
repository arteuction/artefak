<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistApplication;
use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for artist application endpoints:
 *   GET  /api/v1/artist-applications
 *   POST /api/v1/artist-applications
 *   GET  /api/v1/artist-applications/{application}
 *   POST /api/v1/artist-applications/{application}/approve
 *   POST /api/v1/artist-applications/{application}/reject
 */
final class ArtistApplicationApiTest extends TestCase
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

    private function artistWithProfile(string $profileStatus = 'pending'): array
    {
        $user = User::factory()->create(['role' => 'artist']);
        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Artist ' . uniqid(),
            'slug'         => 'artist-' . uniqid(),
            'status'       => $profileStatus,
            'bio'          => 'Test bio.',
        ]);
        return [$user, $profile];
    }

    private function application(ArtistProfile $profile, string $status = 'submitted'): ArtistApplication
    {
        return ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'version'           => 1,
            'status'            => $status,
            'motivation'        => 'I want to share my art.',
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_applications(): void
    {
        $this->getJson('/api/v1/artist-applications')->assertStatus(401);
    }

    public function test_admin_sees_all_applications(): void
    {
        [$artist1, $profile1] = $this->artistWithProfile();
        [$artist2, $profile2] = $this->artistWithProfile();
        $this->application($profile1);
        $this->application($profile2);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/artist-applications')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_admin_can_filter_applications_by_status(): void
    {
        [$artist1, $profile1] = $this->artistWithProfile();
        [$artist2, $profile2] = $this->artistWithProfile();
        $this->application($profile1, 'submitted');
        $this->application($profile2, 'approved');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/artist-applications?status=submitted')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_artist_sees_own_applications_only(): void
    {
        [$artist1, $profile1] = $this->artistWithProfile();
        [$artist2, $profile2] = $this->artistWithProfile();
        $this->application($profile1);
        $this->application($profile2);

        $response = $this->actingAs($artist1)
            ->getJson('/api/v1/artist-applications')
            ->assertOk();

        // artist1 sees only their own
        foreach ($response->json('data') as $item) {
            $this->assertSame($profile1->id, $item['artist_profile_id']);
        }
    }

    public function test_artist_without_profile_gets_empty_list(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/artist-applications')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_submit_application(): void
    {
        $this->postJson('/api/v1/artist-applications', [])->assertStatus(401);
    }

    public function test_artist_with_profile_can_submit_application(): void
    {
        [$artist, $profile] = $this->artistWithProfile('pending');

        $response = $this->actingAs($artist)
            ->postJson('/api/v1/artist-applications', [
                'motivation' => 'I want to share my art with the world.',
            ])
            ->assertStatus(201);

        $this->assertSame($profile->id, $response->json('artist_profile_id'));
    }

    public function test_user_without_profile_cannot_submit_application(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->postJson('/api/v1/artist-applications', ['motivation' => 'Test.'])
            ->assertStatus(422);
    }

    public function test_approved_artist_cannot_resubmit(): void
    {
        [$artist, $profile] = $this->artistWithProfile('approved');

        $this->actingAs($artist)
            ->postJson('/api/v1/artist-applications', ['motivation' => 'Trying again.'])
            ->assertStatus(422);
    }

    public function test_store_validates_portfolio_url_format(): void
    {
        [$artist, $profile] = $this->artistWithProfile();

        $this->actingAs($artist)
            ->postJson('/api/v1/artist-applications', [
                'portfolio_url' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['portfolio_url']);
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_approve_application(): void
    {
        [$artist, $profile] = $this->artistWithProfile();
        $app = $this->application($profile);

        $this->postJson("/api/v1/artist-applications/{$app->id}/approve")
            ->assertStatus(401);
    }

    public function test_buyer_cannot_approve_application(): void
    {
        [$artist, $profile] = $this->artistWithProfile();
        $app = $this->application($profile);

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/artist-applications/{$app->id}/approve")
            ->assertStatus(403);
    }

    public function test_admin_can_approve_submitted_application(): void
    {
        [$artist, $profile] = $this->artistWithProfile();
        $app = $this->application($profile, 'submitted');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/artist-applications/{$app->id}/approve", [
                'note' => 'Strong portfolio.',
            ])
            ->assertOk();

        $this->assertSame('approved', $response->json('status'));
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function test_admin_can_reject_application_with_note(): void
    {
        [$artist, $profile] = $this->artistWithProfile();
        $app = $this->application($profile, 'submitted');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/artist-applications/{$app->id}/reject", [
                'note' => 'Insufficient portfolio.',
            ])
            ->assertOk();

        $this->assertSame('rejected', $response->json('status'));
    }

    public function test_reject_requires_note(): void
    {
        [$artist, $profile] = $this->artistWithProfile();
        $app = $this->application($profile, 'submitted');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/artist-applications/{$app->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);
    }
}
