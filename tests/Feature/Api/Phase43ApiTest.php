<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 43: Ledger admin view, Refunds admin view.
 */
final class Phase43ApiTest extends TestCase
{
    use RefreshDatabase;

    private function insertSettlementAndLedger(): int
    {
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'BGN',
            'profile_key'              => 'default',
            'profile_version'          => 1,
            'artist_bps'               => 7500,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 1500,
            'artist_cents'             => 7500,
            'fund_cents'               => 1000,
            'ops_cents'                => 1500,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::table('ledger_entries')->insert([
            ['settlement_id' => $settlementId, 'type' => 'credit', 'amount_cents' => 10000, 'currency' => 'BGN', 'idempotency_key' => 'ik_cr_' . uniqid(), 'created_at' => now(), 'updated_at' => now()],
            ['settlement_id' => $settlementId, 'type' => 'debit',  'amount_cents' => 2500,  'currency' => 'BGN', 'idempotency_key' => 'ik_db_' . uniqid(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $settlementId;
    }

    // ── Ledger ───────────────────────────────────────────────────────────────

    public function test_admin_can_view_ledger(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->insertSettlementAndLedger();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/ledger')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'balance_cents', 'total_credits', 'total_debits']);
    }

    public function test_ledger_balance_is_correct(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->insertSettlementAndLedger();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/ledger')
            ->assertOk();

        $this->assertEquals(7500, $response->json('balance_cents')); // 10000 - 2500
    }

    public function test_non_admin_cannot_view_ledger(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/ledger')
            ->assertForbidden();
    }

    // ── Refunds ──────────────────────────────────────────────────────────────

    public function test_admin_can_view_refunds(): void
    {
        $admin        = User::factory()->create(['role' => 'admin']);
        $settlementId = $this->insertSettlementAndLedger();

        DB::table('refunds')->insert([
            'stripe_refund_id'       => 're_' . uniqid(),
            'settlement_id'          => $settlementId,
            'amount_cents'           => 5000,
            'currency'               => 'BGN',
            'refund_status'          => 'succeeded',
            'reconciliation_status'  => 'completed',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/refunds')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_view_refunds(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/refunds')
            ->assertForbidden();
    }
}
