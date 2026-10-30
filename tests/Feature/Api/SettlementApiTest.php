<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for admin settlement endpoints:
 *   GET /api/v1/admin/settlements
 *   GET /api/v1/admin/settlements/{id}
 */
final class SettlementApiTest extends TestCase
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

    private function settlement(string $status = 'pending'): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('settlements')->insertGetId([
            'auction_id'               => 1,
            'status'                   => $status,
            'gross_cents'              => 100000,
            'stripe_payment_intent_id' => 'pi_test_' . uniqid(),
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 45000,
            'fund_cents'               => 45000,
            'ops_cents'                => 10000,
            'currency'                 => 'EUR',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/settlements')->assertStatus(401);
    }

    public function test_buyer_cannot_list_settlements(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/settlements')
            ->assertStatus(403);
    }

    public function test_admin_can_list_settlements(): void
    {
        $this->settlement('pending');
        $this->settlement('completed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/settlements')
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('total', $response->json());
        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $this->settlement('pending');
        $this->settlement('completed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/settlements?status=completed')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/settlements/1')->assertStatus(401);
    }

    public function test_admin_can_show_settlement(): void
    {
        $id = $this->settlement('pending');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/settlements/{$id}")
            ->assertOk();

        $this->assertArrayHasKey('settlement', $response->json());
        $this->assertArrayHasKey('lines', $response->json());
        $this->assertSame($id, $response->json('settlement.id'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/settlements/99999999')
            ->assertStatus(404);
    }
}
