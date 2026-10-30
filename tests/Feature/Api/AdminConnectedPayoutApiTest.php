<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ConnectedAccountPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin connected payout endpoints:
 *   GET /api/v1/admin/connected-payouts
 *   GET /api/v1/admin/connected-payouts/{id}
 */
final class AdminConnectedPayoutApiTest extends TestCase
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

    private function payout(string $status = 'paid'): ConnectedAccountPayout
    {
        return ConnectedAccountPayout::create([
            'stripe_payout_id'  => 'po_' . uniqid(),
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'amount_cents'      => 10000,
            'currency'          => 'EUR',
            'status'            => $status,
            'arrival_date'      => now()->toDateString(),
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/connected-payouts')->assertStatus(401);
    }

    public function test_buyer_cannot_access_connected_payouts(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/connected-payouts')
            ->assertStatus(403);
    }

    public function test_admin_can_list_connected_payouts(): void
    {
        $this->payout('paid');
        $this->payout('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts')
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('total', $response->json());
        $this->assertArrayHasKey('summary', $response->json());
        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $this->payout('paid');
        $this->payout('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts?status=failed')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    public function test_index_can_filter_by_account(): void
    {
        $p1 = $this->payout('paid');
        $this->payout('paid');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/connected-payouts?account={$p1->stripe_account_id}")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/connected-payouts/1')->assertStatus(401);
    }

    public function test_admin_can_show_payout(): void
    {
        $payout = $this->payout('paid');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/connected-payouts/{$payout->id}")
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertSame($payout->id, $response->json('data.id'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts/99999999')
            ->assertStatus(404);
    }
}
