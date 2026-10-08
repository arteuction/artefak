<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 42: SplitProfile admin CRUD, GalleryStaff role update.
 */
final class Phase42ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeGallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'G' . uniqid(), 'slug' => 'g-' . uniqid(),
            'type'   => 'private', 'status' => 'active',
        ]);
    }

    // ── SplitProfile ─────────────────────────────────────────────────────────

    public function test_admin_can_create_split_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'gallery-standard',
                'artist_bps'     => 7500,
                'fund_bps'       => 1000,
                'ops_bps'        => 1500,
                'effective_from' => '2026-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1);
    }

    public function test_split_profile_bps_must_sum_to_10000(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'bad-profile',
                'artist_bps'     => 5000,
                'fund_bps'       => 1000,
                'ops_bps'        => 1000, // total=7000
                'effective_from' => '2026-01-01',
            ])
            ->assertUnprocessable();
    }

    public function test_split_profile_version_increments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'incremental',
                'artist_bps'     => 7500,
                'fund_bps'       => 1000,
                'ops_bps'        => 1500,
                'effective_from' => '2026-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'incremental',
                'artist_bps'     => 8000,
                'fund_bps'       => 1000,
                'ops_bps'        => 1000,
                'effective_from' => '2026-06-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.version', 2);
    }

    public function test_non_admin_cannot_create_split_profile(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'hacked',
                'artist_bps'     => 10000,
                'fund_bps'       => 0,
                'ops_bps'        => 0,
                'effective_from' => '2026-01-01',
            ])
            ->assertForbidden();
    }

    // ── GalleryStaff role update ──────────────────────────────────────────────

    public function test_admin_can_update_staff_role(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'artist']);
        $gallery = $this->makeGallery();
        $staff = GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $member->id,
            'role'        => 'curator',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/galleries/{$gallery->id}/staff/{$staff->id}", ['role' => 'sales'])
            ->assertOk()
            ->assertJsonPath('role', 'sales');
    }
}
