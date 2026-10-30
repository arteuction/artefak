<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ConnectedAccountPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin payout visibility endpoints:
 *   GET /api/v1/admin/connected-payouts
 *   GET /api/v1/admin/connected-payouts/{id}
 *   GET /api/v1/admin/settlement-lines
 */
final class AdminPayoutApiTest extends TestCase
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
            'stripe_account_id' => 'acct_test',
            'amount_cents'      => 100000,
            'currency'          => 'EUR',
            'status'            => $status,
        ]);
    }

    // ── GET /admin/connected-payouts ──────────────────────────────────────────

    public function test_unauthenticated_cannot_list_connected_payouts(): void
    {
        $this->getJson('/api/v1/admin/connected-payouts')->assertStatus(401);
    }

    public function test_buyer_cannot_list_connected_payouts(): void
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

        $this->assertSame(2, $response->json('total'));
    }

    public function test_connected_payouts_can_filter_by_status(): void
    {
        $this->payout('paid');
        $this->payout('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts?status=failed')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    public function test_connected_payouts_can_filter_by_account(): void
    {
        ConnectedAccountPayout::create([
            'stripe_payout_id'  => 'po_acct1_' . uniqid(),
            'stripe_account_id' => 'acct_aaa',
            'amount_cents'      => 50000,
            'currency'          => 'EUR',
            'status'            => 'paid',
        ]);
        ConnectedAccountPayout::create([
            'stripe_payout_id'  => 'po_acct2_' . uniqid(),
            'stripe_account_id' => 'acct_bbb',
            'amount_cents'      => 75000,
            'currency'          => 'EUR',
            'status'            => 'paid',
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts?account=acct_aaa')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_connected_payouts_includes_summary(): void
    {
        $this->payout('paid');
        $this->payout('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts')
            ->assertOk();

        $this->assertArrayHasKey('summary', $response->json());
    }

    // ── GET /admin/connected-payouts/{id} ─────────────────────────────────────

    public function test_unauthenticated_cannot_show_connected_payout(): void
    {
        $payout = $this->payout();

        $this->getJson("/api/v1/admin/connected-payouts/{$payout->id}")->assertStatus(401);
    }

    public function test_admin_can_show_connected_payout(): void
    {
        $payout = $this->payout('failed');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/connected-payouts/{$payout->id}")
            ->assertOk();

        $this->assertSame('failed', $response->json('data.status'));
        $this->assertSame($payout->id, $response->json('data.id'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/connected-payouts/99999999')
            ->assertStatus(404);
    }

    // ── GET /admin/settlement-lines ───────────────────────────────────────────

    public function test_unauthenticated_cannot_list_settlement_lines(): void
    {
        $this->getJson('/api/v1/admin/settlement-lines')->assertStatus(401);
    }

    public function test_buyer_cannot_list_settlement_lines(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/settlement-lines')
            ->assertStatus(403);
    }

    public function test_admin_can_list_settlement_lines(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/settlement-lines')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }
}
