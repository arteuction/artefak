<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin auction ruleset endpoints:
 *   GET   /api/v1/admin/rulesets
 *   GET   /api/v1/admin/rulesets/{ruleset}
 *   POST  /api/v1/admin/rulesets
 *   PATCH /api/v1/admin/rulesets/{ruleset}
 */
final class AdminRulesetApiTest extends TestCase
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

    private function ruleset(string $name = 'Standard'): AuctionRuleset
    {
        return AuctionRuleset::create([
            'name'                        => $name,
            'default_bid_increment_cents' => 1000,
            'anti_sniping_seconds'        => 300,
            'proxy_bid_enabled'           => true,
            'reserve_enabled'             => false,
        ]);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_rulesets(): void
    {
        $this->getJson('/api/v1/admin/rulesets')->assertStatus(401);
    }

    public function test_buyer_cannot_list_rulesets(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/rulesets')
            ->assertStatus(403);
    }

    public function test_admin_can_list_rulesets(): void
    {
        $this->ruleset('Standard');
        $this->ruleset('Reserve Only');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/rulesets')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_returns_empty_when_no_rulesets(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/rulesets')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_ruleset(): void
    {
        $ruleset = $this->ruleset();

        $this->getJson("/api/v1/admin/rulesets/{$ruleset->id}")->assertStatus(401);
    }

    public function test_buyer_cannot_show_ruleset(): void
    {
        $ruleset = $this->ruleset();

        $this->actingAs($this->buyer())
            ->getJson("/api/v1/admin/rulesets/{$ruleset->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_show_ruleset(): void
    {
        $ruleset = $this->ruleset('My Ruleset');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/rulesets/{$ruleset->id}")
            ->assertOk();

        $this->assertSame('My Ruleset', $response->json('data.name'));
    }

    public function test_show_returns_404_for_unknown_ruleset(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/rulesets/99999999')
            ->assertStatus(404);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_ruleset(): void
    {
        $this->postJson('/api/v1/admin/rulesets', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_ruleset(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/admin/rulesets', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_ruleset(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/rulesets', [
                'name'                        => 'Classic Auction',
                'default_bid_increment_cents' => 5000,
                'anti_sniping_seconds'        => 600,
                'proxy_bid_enabled'           => true,
                'reserve_enabled'             => true,
            ])
            ->assertStatus(201);

        $this->assertSame('Classic Auction', $response->json('data.name'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/rulesets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_tie_policy_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/rulesets', [
                'name'       => 'Bad Policy',
                'tie_policy' => 'not_valid',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tie_policy']);
    }

    public function test_store_validates_max_bid_policy_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/rulesets', [
                'name'           => 'Bad MaxBid',
                'max_bid_policy' => 'invalid_option',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_bid_policy']);
    }

    public function test_store_rejects_bid_increment_below_minimum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/rulesets', [
                'name'                        => 'Too Small',
                'default_bid_increment_cents' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_bid_increment_cents']);
    }
}
