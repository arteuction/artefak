<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistApplication;
use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtistApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeArtist(): array
    {
        $user = User::factory()->create(['role' => 'artist']);
        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Test Artist',
            'slug'         => 'test-artist-' . $user->id,
            'status'       => 'pending',
        ]);
        return [$user, $profile];
    }

    public function test_artist_can_submit_application(): void
    {
        [$user] = $this->makeArtist();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artist-applications', [
                'portfolio_url' => 'https://example.com/portfolio',
                'motivation'    => 'I am passionate about art.',
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'submitted']);
    }

    public function test_artist_cannot_submit_without_profile(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artist-applications', ['motivation' => 'test'])
            ->assertStatus(422);
    }

    public function test_admin_can_approve_application(): void
    {
        [$artist, $profile] = $this->makeArtist();
        $admin = User::factory()->create(['role' => 'admin']);

        $application = ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'status'            => 'submitted',
            'version'           => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/artist-applications/{$application->id}/approve", [
                'note' => 'Portfolio looks great.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_admin_can_reject_application(): void
    {
        [$artist, $profile] = $this->makeArtist();
        $admin = User::factory()->create(['role' => 'admin']);

        $application = ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'status'            => 'submitted',
            'version'           => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/artist-applications/{$application->id}/reject", [
                'note' => 'Does not meet requirements.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_artist_can_list_own_applications(): void
    {
        [$artist, $profile] = $this->makeArtist();

        ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'status'            => 'submitted',
            'version'           => 1,
        ]);

        $this->actingAs($artist, 'sanctum')
            ->getJson('/api/v1/artist-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_approve(): void
    {
        [$artist, $profile] = $this->makeArtist();

        $application = ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'status'            => 'submitted',
            'version'           => 1,
        ]);

        $this->actingAs($artist, 'sanctum')
            ->postJson("/api/v1/artist-applications/{$application->id}/approve", ['note' => 'test'])
            ->assertForbidden();
    }
}
