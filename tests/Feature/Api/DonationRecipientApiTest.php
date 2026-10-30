<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DonationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for donation recipient endpoints:
 *   GET   /api/v1/donation-recipients
 *   GET   /api/v1/donation-recipients/{recipient}
 *   POST  /api/v1/donation-recipients
 *   PATCH /api/v1/donation-recipients/{recipient}
 */
final class DonationRecipientApiTest extends TestCase
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

    private function recipient(string $status = 'active'): DonationRecipient
    {
        return DonationRecipient::create([
            'name'              => 'NGO ' . uniqid(),
            'eik'               => (string) rand(100000000, 999999999),
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 5000,
            'status'            => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_public_sees_only_active_recipients(): void
    {
        $this->recipient('active');
        $this->recipient('inactive');

        $response = $this->getJson('/api/v1/donation-recipients')->assertOk();

        foreach ($response->json('data') as $item) {
            $this->assertSame('active', $item['status']);
        }
    }

    public function test_admin_sees_all_recipients(): void
    {
        $this->recipient('active');
        $this->recipient('inactive');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/donation-recipients')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_can_filter_by_status(): void
    {
        $this->recipient('active');
        $this->recipient('inactive');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/donation-recipients?status=inactive')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_is_public(): void
    {
        $recipient = $this->recipient();

        $this->getJson("/api/v1/donation-recipients/{$recipient->id}")->assertOk();
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->getJson('/api/v1/donation-recipients/99999999')->assertStatus(404);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_recipient(): void
    {
        $this->postJson('/api/v1/donation-recipients', ['name' => 'Test'])->assertStatus(401);
    }

    public function test_buyer_cannot_create_recipient(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/donation-recipients', ['name' => 'Test'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_recipient(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Детска фондация',
                'eik'               => '123456789',
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 5000,
            ])
            ->assertStatus(201);

        $this->assertSame('active', $response->json('status'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'eik', 'legal_type', 'eligibility_basis', 'deduction_bps']);
    }

    public function test_store_validates_legal_type_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Test',
                'eik'               => '111111111',
                'legal_type'        => 'corporation',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_type']);
    }

    public function test_store_validates_unique_eik(): void
    {
        $existing = $this->recipient();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Duplicate EIK',
                'eik'               => $existing->eik,
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['eik']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_recipient(): void
    {
        $recipient = $this->recipient();

        $this->patchJson("/api/v1/donation-recipients/{$recipient->id}", ['name' => 'New'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_update_recipient(): void
    {
        $recipient = $this->recipient();

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", ['name' => 'New'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_recipient(): void
    {
        $recipient = $this->recipient();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", [
                'name'   => 'Updated Name',
                'status' => 'inactive',
            ])
            ->assertOk();

        $this->assertSame('Updated Name', $response->json('name'));
        $this->assertSame('inactive', $response->json('status'));
    }
}
