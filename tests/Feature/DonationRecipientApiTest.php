<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DonationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for /api/v1/donation-recipients — public list + admin CRUD.
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
            'name'              => 'Recipient ' . uniqid(),
            'eik'               => (string) random_int(100000000, 999999999),
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => $status,
        ]);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_public_index_shows_only_active_recipients(): void
    {
        $this->recipient('active');
        $this->recipient('inactive');

        $response = $this->getJson('/api/v1/donation-recipients')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('active', $response->json('data.0.status'));
    }

    public function test_unauthenticated_public_index_excludes_inactive(): void
    {
        $this->recipient('inactive');

        $response = $this->getJson('/api/v1/donation-recipients')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_buyer_index_also_sees_only_active(): void
    {
        $this->recipient('active');
        $this->recipient('inactive');

        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/donation-recipients')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_index_sees_all_statuses(): void
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
        $this->assertSame('inactive', $response->json('data.0.status'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_returns_recipient(): void
    {
        $recipient = $this->recipient();

        $this->getJson("/api/v1/donation-recipients/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $recipient->id);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_recipient(): void
    {
        $this->postJson('/api/v1/donation-recipients', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_recipient(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/donation-recipients', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_recipient(): void
    {
        $eik = (string) random_int(100000000, 999999999);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'New Charity',
                'eik'               => $eik,
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 500,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('donation_recipients', ['eik' => $eik, 'status' => 'active']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'eik', 'legal_type', 'eligibility_basis', 'deduction_bps']);
    }

    public function test_store_rejects_invalid_legal_type(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Test',
                'eik'               => '123456789',
                'legal_type'        => 'corporation',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_type']);
    }

    public function test_store_rejects_duplicate_eik(): void
    {
        $existing = $this->recipient();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Duplicate',
                'eik'               => $existing->eik,
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['eik']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_recipient(): void
    {
        $recipient = $this->recipient();

        $this->patchJson("/api/v1/donation-recipients/{$recipient->id}", [])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_update_recipient(): void
    {
        $recipient = $this->recipient();

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_recipient(): void
    {
        $recipient = $this->recipient();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", [
                'status' => 'inactive',
                'name'   => 'Updated Name',
            ])
            ->assertOk();

        $this->assertDatabaseHas('donation_recipients', [
            'id'     => $recipient->id,
            'status' => 'inactive',
            'name'   => 'Updated Name',
        ]);
    }

    public function test_update_rejects_invalid_eligibility_basis(): void
    {
        $recipient = $this->recipient();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", [
                'eligibility_basis' => 'INVALID_BASIS',
            ])
            ->assertStatus(422);
    }
}
