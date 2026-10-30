<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\GovernancePolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for governance (consent/GDPR) endpoints:
 *   GET    /api/v1/governance/policies
 *   POST   /api/v1/governance/policies/{policy}/consent
 *   DELETE /api/v1/governance/policies/{policy}/consent
 *   GET    /api/v1/my/consents
 *   POST   /api/v1/my/erasure-request
 */
final class GovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function policy(bool $isActive = true, string $type = 'terms_of_service'): GovernancePolicy
    {
        return GovernancePolicy::create([
            'type'           => $type,
            'version'        => '1.' . uniqid(),
            'effective_from' => now()->subDay(),
            'is_active'      => $isActive,
        ]);
    }

    // ── GET /governance/policies ──────────────────────────────────────────────

    public function test_policies_is_public(): void
    {
        $this->getJson('/api/v1/governance/policies')->assertOk();
    }

    public function test_policies_returns_only_active(): void
    {
        $this->policy(isActive: true);
        $this->policy(isActive: false);

        $response = $this->getJson('/api/v1/governance/policies')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_policies_response_structure(): void
    {
        $this->policy();

        $this->getJson('/api/v1/governance/policies')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'type', 'version', 'effective_from']],
            ]);
    }

    // ── POST /governance/policies/{policy}/consent ────────────────────────────

    public function test_unauthenticated_cannot_consent(): void
    {
        $policy = $this->policy();

        $this->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertStatus(401);
    }

    public function test_buyer_can_consent_to_active_policy(): void
    {
        $buyer  = $this->buyer();
        $policy = $this->policy();

        $response = $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertOk();

        $this->assertSame($buyer->id, $response->json('data.user_id'));
        $this->assertSame($policy->id, $response->json('data.governance_policy_id'));
    }

    public function test_consent_is_idempotent(): void
    {
        $buyer  = $this->buyer();
        $policy = $this->policy();

        $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertOk();

        $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertOk();

        $this->assertDatabaseCount('user_consents', 1);
    }

    public function test_consent_fails_for_inactive_policy(): void
    {
        $buyer  = $this->buyer();
        $policy = $this->policy(isActive: false);

        $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertStatus(500); // InvalidArgumentException bubbles as 500 without handler
    }

    // ── DELETE /governance/policies/{policy}/consent ──────────────────────────

    public function test_unauthenticated_cannot_withdraw_consent(): void
    {
        $policy = $this->policy();

        $this->deleteJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertStatus(401);
    }

    public function test_buyer_can_withdraw_consent(): void
    {
        $buyer  = $this->buyer();
        $policy = $this->policy();

        // First consent
        $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertOk();

        // Then withdraw
        $this->actingAs($buyer)
            ->deleteJson("/api/v1/governance/policies/{$policy->id}/consent")
            ->assertStatus(204);

        $this->assertDatabaseHas('user_consents', [
            'user_id'              => $buyer->id,
            'governance_policy_id' => $policy->id,
        ]);
        // withdrawn_at is set
        $row = \Illuminate\Support\Facades\DB::table('user_consents')
            ->where('user_id', $buyer->id)
            ->where('governance_policy_id', $policy->id)
            ->first();
        $this->assertNotNull($row->withdrawn_at);
    }

    // ── GET /my/consents ──────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_own_consents(): void
    {
        $this->getJson('/api/v1/my/consents')->assertStatus(401);
    }

    public function test_buyer_can_view_own_consents(): void
    {
        $buyer  = $this->buyer();
        $policy = $this->policy();

        $this->actingAs($buyer)
            ->postJson("/api/v1/governance/policies/{$policy->id}/consent");

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/my/consents')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_my_consents_returns_empty_when_none(): void
    {
        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/my/consents')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ── POST /my/erasure-request ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_request_erasure(): void
    {
        $this->postJson('/api/v1/my/erasure-request')->assertStatus(401);
    }

    public function test_buyer_can_request_erasure(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->postJson('/api/v1/my/erasure-request')
            ->assertOk();

        $this->assertArrayHasKey('message', $response->json());
    }
}
