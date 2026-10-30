<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin user management endpoints:
 *   GET   /api/v1/admin/users
 *   GET   /api/v1/admin/users/{user}
 *   PATCH /api/v1/admin/users/{user}
 */
final class UserAdminApiTest extends TestCase
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

    // ── index ─────────────────────────────────────────────────────────────────

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

    public function test_admin_can_list_users(): void
    {
        $this->buyer();
        $this->buyer();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_index_can_filter_by_role(): void
    {
        $this->buyer();
        User::factory()->create(['role' => 'artist']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users?role=artist')
            ->assertOk();

        foreach ($response->json('data') as $user) {
            $this->assertSame('artist', $user['role']);
        }
    }

    public function test_index_can_filter_by_email_substring(): void
    {
        User::factory()->create(['email' => 'alice@example.com']);
        User::factory()->create(['email' => 'bob@example.com']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users?email=alice')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertStringContainsString('alice', $response->json('data.0.email'));
    }

    public function test_index_does_not_expose_password(): void
    {
        $this->buyer();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        foreach ($response->json('data') as $user) {
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_user(): void
    {
        $user = $this->buyer();
        $this->getJson("/api/v1/admin/users/{$user->id}")->assertStatus(401);
    }

    public function test_buyer_cannot_show_user(): void
    {
        $user = $this->buyer();
        $this->actingAs($this->buyer())
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_show_user(): void
    {
        $user = $this->buyer();

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk();

        $this->assertSame($user->id, $response->json('data.id'));
        $this->assertSame($user->email, $response->json('data.email'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/users/99999999')
            ->assertStatus(404);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_user(): void
    {
        $user = $this->buyer();
        $this->patchJson("/api/v1/admin/users/{$user->id}", ['role' => 'artist'])->assertStatus(401);
    }

    public function test_buyer_cannot_update_user(): void
    {
        $user = $this->buyer();
        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/admin/users/{$user->id}", ['role' => 'artist'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_user_role(): void
    {
        $user = $this->buyer();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$user->id}", ['role' => 'artist'])
            ->assertOk();

        $this->assertSame('artist', $response->json('data.role'));
    }

    public function test_update_validates_role_enum(): void
    {
        $user = $this->buyer();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$user->id}", ['role' => 'superadmin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_update_validates_required_role(): void
    {
        $user = $this->buyer();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$user->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }
}
