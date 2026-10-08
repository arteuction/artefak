<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DonationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 40: DonationRecipient public list, admin CRUD.
 */
final class Phase40ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeRecipient(string $status = 'active'): DonationRecipient
    {
        return DonationRecipient::create([
            'name'              => 'Recipient ' . uniqid(),
            'eik'               => (string) rand(100000000, 999999999),
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => $status,
        ]);
    }

    // ── Public list ──────────────────────────────────────────────────────────

    public function test_public_sees_only_active_recipients(): void
    {
        $this->makeRecipient('active');
        $this->makeRecipient('inactive');

        $this->getJson('/api/v1/donation-recipients')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_sees_all_recipients(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeRecipient('active');
        $this->makeRecipient('inactive');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/donation-recipients')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Admin CRUD ───────────────────────────────────────────────────────────

    public function test_admin_can_create_donation_recipient(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Foundation ART',
                'eik'               => '987654321',
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_3_PATRONAGE',
                'deduction_bps'     => 1500,
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Foundation ART']);
    }

    public function test_non_admin_cannot_create_donation_recipient(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/donation-recipients', [
                'name'              => 'Bad Foundation',
                'eik'               => '123456789',
                'legal_type'        => 'ngo',
                'eligibility_basis' => 'ZKPO_ART31_1',
                'deduction_bps'     => 1000,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_donation_recipient(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $recipient = $this->makeRecipient();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/donation-recipients/{$recipient->id}", [
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'inactive']);
    }
}
