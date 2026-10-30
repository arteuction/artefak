<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET/PATCH /api/v1/admin/users — admin-only user management.
 */
final class UserAdminApiTest extends TestCase
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

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_users(): void
    {
        $this->getJson('/api/v1/admin/users')->assertStatus(401);
    }

    public function test_buyer_cannot_list_users(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_artist_cannot_list_users(): void
    {
        $this->actingAs($this->artist())
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        User::factory()->count(3)->create(['role' => 'buyer']);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_operator_can_list_users(): void
    {
        $this->actingAs($this->operator())
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        User::factory()->count(2)->create(['role' => 'buyer']);
        User::factory()->count(3)->create(['role' => 'artist']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users?role=artist')
            ->assertOk();

        $this->assertSame('artist', $response->json('data.0.role'));
    }

    public function test_admin_can_filter_users_by_email(): void
    {
        User::factory()->create(['email' => 'unique_search_term@example.com', 'role' => 'buyer']);
        User::factory()->count(5)->create(['role' => 'buyer']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users?email=unique_search_term')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_user(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/v1/admin/users/{$user->id}")->assertStatus(401);
    }

    public function test_buyer_cannot_view_user(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->buyer())
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_view_user(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.email', $target->email);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_user_role(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'artist'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_update_user_role(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'artist'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_user_role(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'artist'])
            ->assertOk()
            ->assertJsonPath('data.role', 'artist');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'artist']);
    }

    public function test_operator_can_update_user_role(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($this->operator())
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'artist'])
            ->assertOk();
    }

    public function test_update_rejects_invalid_role(): void
    {
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'superadmin'])
            ->assertStatus(422);
    }

    public function test_update_returns_404_for_missing_user(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/v1/admin/users/99999999', ['role' => 'artist'])
            ->assertStatus(404);
    }
}
