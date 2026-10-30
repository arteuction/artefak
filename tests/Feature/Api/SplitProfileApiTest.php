<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for /api/v1/admin/split-profiles — admin-only split profile CRUD.
 *
 * Split profiles are a critical financial configuration.
 * artist_bps + fund_bps + ops_bps MUST equal 10000.
 */
final class SplitProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => 'operator']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function createProfile(string $key = 'test_profile', int $version = 1): int
    {
        return DB::table('split_profiles')->insertGetId([
            'profile_key'    => $key,
            'version'        => $version,
            'artist_bps'     => 8000,
            'fund_bps'       => 1000,
            'ops_bps'        => 1000,
            'status'         => 'active',
            'effective_from' => now()->subDay()->toDateString(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_split_profiles(): void
    {
        $this->getJson('/api/v1/admin/split-profiles')->assertStatus(401);
    }

    public function test_buyer_cannot_list_split_profiles(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/split-profiles')
            ->assertStatus(403);
    }

    public function test_admin_can_list_split_profiles(): void
    {
        $this->createProfile('arteuction_45_45_10');
        $this->createProfile('library_80_10_10');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/split-profiles')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_operator_can_list_split_profiles(): void
    {
        $this->actingAs($this->operator())
            ->getJson('/api/v1/admin/split-profiles')
            ->assertOk();
    }

    public function test_index_can_filter_by_profile_key(): void
    {
        $this->createProfile('arteuction_45_45_10');
        $this->createProfile('library_80_10_10');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/split-profiles?profile_key=library_80_10_10')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('library_80_10_10', $response->json('data.0.profile_key'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $id1 = $this->createProfile('key_one');
        $this->createProfile('key_two');

        // Deprecate key_one
        DB::table('split_profiles')->where('id', $id1)
            ->update(['status' => 'deprecated', 'updated_at' => now()]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/split-profiles?status=deprecated')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('deprecated', $response->json('data.0.status'));
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_split_profile(): void
    {
        $this->postJson('/api/v1/admin/split-profiles', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_split_profile(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/admin/split-profiles', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_split_profile(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'new_profile',
                'artist_bps'     => 7000,
                'fund_bps'       => 2000,
                'ops_bps'        => 1000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame('new_profile', $response->json('data.profile_key'));
        $this->assertSame(1, $response->json('data.version'));
        $this->assertSame('active', $response->json('data.status'));
    }

    public function test_store_auto_increments_version_for_same_key(): void
    {
        $this->createProfile('my_profile', 1);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'my_profile',
                'artist_bps'     => 7000,
                'fund_bps'       => 2000,
                'ops_bps'        => 1000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame(2, $response->json('data.version'));
    }

    public function test_store_rejects_bps_not_summing_to_10000(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'bad_profile',
                'artist_bps'     => 5000,
                'fund_bps'       => 5000,
                'ops_bps'        => 1000, // 5000+5000+1000 = 11000
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_invalid_profile_key_characters(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'My Profile!',
                'artist_bps'     => 8000,
                'fund_bps'       => 1000,
                'ops_bps'        => 1000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_key']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_key', 'artist_bps', 'fund_bps', 'ops_bps', 'effective_from']);
    }

    // ── deprecate ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_deprecate_split_profile(): void
    {
        $id = $this->createProfile();

        $this->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertStatus(401);
    }

    public function test_buyer_cannot_deprecate_split_profile(): void
    {
        $id = $this->createProfile();

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertStatus(403);
    }

    public function test_admin_can_deprecate_active_profile(): void
    {
        $id = $this->createProfile('to_deprecate');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertOk()
            ->assertJsonPath('data.status', 'deprecated');

        $this->assertSame('deprecated', DB::table('split_profiles')->find($id)->status);
    }

    public function test_deprecate_returns_404_for_unknown_id(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/v1/admin/split-profiles/99999999/deprecate')
            ->assertStatus(404);
    }

    public function test_deprecate_rejects_superseded_profile(): void
    {
        $id = $this->createProfile('superseded_key');
        DB::table('split_profiles')->where('id', $id)
            ->update(['status' => 'superseded', 'updated_at' => now()]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertStatus(422);
    }

    public function test_bps_conservation_invariant_zero_sum_profile_is_valid(): void
    {
        // All three to 0 sums to 0, not 10000 → must be rejected
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/split-profiles', [
                'profile_key'    => 'zero_split',
                'artist_bps'     => 0,
                'fund_bps'       => 0,
                'ops_bps'        => 0,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }
}
