<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for donation endpoints:
 *   GET  /api/v1/donations
 *   POST /api/v1/donations
 *   GET  /api/v1/donations/{donation}
 *   GET  /api/v1/donations/tax-receipt/{year?}
 */
final class DonationApiTest extends TestCase
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

    private function recipient(): DonationRecipient
    {
        return DonationRecipient::create([
            'name'              => 'NGO ' . uniqid(),
            'eik'               => (string) rand(100000000, 999999999),
            'status'            => 'active',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
        ]);
    }

    private function donation(User $donor, DonationRecipient $recipient, string $status = 'confirmed'): Donation
    {
        return Donation::create([
            'donor_id'              => $donor->id,
            'donation_recipient_id' => $recipient->id,
            'donated_cents'         => 5000,
            'currency'              => 'EUR',
            'status'                => $status,
            'eligibility_basis'     => 'ZKPO_ART31_1',
            'deduction_bps'         => 1000,
            'max_deductible_cents'  => 500,
            'idempotency_key'       => uniqid('don_', true),
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_donations(): void
    {
        $this->getJson('/api/v1/donations')->assertStatus(401);
    }

    public function test_buyer_sees_only_own_donations(): void
    {
        $buyer1    = $this->buyer();
        $buyer2    = $this->buyer();
        $recipient = $this->recipient();

        $this->donation($buyer1, $recipient);
        $this->donation($buyer2, $recipient);

        $response = $this->actingAs($buyer1)
            ->getJson('/api/v1/donations')
            ->assertOk();

        foreach ($response->json('data.data') as $item) {
            $this->assertSame($buyer1->id, $item['donor_id']);
        }
    }

    public function test_admin_sees_all_donations(): void
    {
        $buyer1    = $this->buyer();
        $buyer2    = $this->buyer();
        $recipient = $this->recipient();

        $this->donation($buyer1, $recipient);
        $this->donation($buyer2, $recipient);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/donations')
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $buyer     = $this->buyer();
        $recipient = $this->recipient();

        $this->donation($buyer, $recipient, 'confirmed');
        $this->donation($buyer, $recipient, 'pending');

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/donations?status=confirmed')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_donation(): void
    {
        $recipient = $this->recipient();

        $this->postJson('/api/v1/donations', [
            'donation_recipient_id' => $recipient->id,
            'donated_cents'         => 1000,
        ])->assertStatus(401);
    }

    public function test_buyer_can_create_donation(): void
    {
        $buyer     = $this->buyer();
        $recipient = $this->recipient();

        $response = $this->actingAs($buyer)
            ->postJson('/api/v1/donations', [
                'donation_recipient_id' => $recipient->id,
                'donated_cents'         => 5000,
            ])
            ->assertStatus(201);

        $this->assertSame($buyer->id, $response->json('data.donor_id'));
        $this->assertSame(5000, $response->json('data.donated_cents'));
    }

    public function test_store_validates_minimum_donated_cents(): void
    {
        $recipient = $this->recipient();

        $this->actingAs($this->buyer())
            ->postJson('/api/v1/donations', [
                'donation_recipient_id' => $recipient->id,
                'donated_cents'         => 50,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donated_cents']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/donations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donation_recipient_id', 'donated_cents']);
    }

    public function test_store_validates_recipient_exists(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/donations', [
                'donation_recipient_id' => 99999999,
                'donated_cents'         => 5000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donation_recipient_id']);
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_donation(): void
    {
        $buyer     = $this->buyer();
        $recipient = $this->recipient();
        $donation  = $this->donation($buyer, $recipient);

        $this->getJson("/api/v1/donations/{$donation->id}")->assertStatus(401);
    }

    public function test_donor_can_show_own_donation(): void
    {
        $buyer     = $this->buyer();
        $recipient = $this->recipient();
        $donation  = $this->donation($buyer, $recipient);

        $response = $this->actingAs($buyer)
            ->getJson("/api/v1/donations/{$donation->id}")
            ->assertOk();

        $this->assertSame($donation->id, $response->json('data.id'));
    }

    public function test_other_buyer_cannot_show_donation(): void
    {
        $buyer1    = $this->buyer();
        $buyer2    = $this->buyer();
        $recipient = $this->recipient();
        $donation  = $this->donation($buyer1, $recipient);

        $this->actingAs($buyer2)
            ->getJson("/api/v1/donations/{$donation->id}")
            ->assertStatus(403);
    }

    // ── tax-receipt ───────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_get_tax_receipt(): void
    {
        $this->getJson('/api/v1/donations/tax-receipt')->assertStatus(401);
    }

    public function test_buyer_can_get_tax_receipt(): void
    {
        $buyer     = $this->buyer();
        $recipient = $this->recipient();
        $this->donation($buyer, $recipient, 'confirmed');

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/donations/tax-receipt')
            ->assertOk();

        $this->assertArrayHasKey('fiscal_year', $response->json());
        $this->assertArrayHasKey('donations', $response->json());
    }

    public function test_tax_receipt_can_specify_year(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/donations/tax-receipt/2024')
            ->assertOk();

        $this->assertSame(2024, $response->json('fiscal_year'));
    }
}
