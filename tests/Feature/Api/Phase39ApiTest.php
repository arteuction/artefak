<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 39: Settlement admin view, Reconciliation runs.
 */
final class Phase39ApiTest extends TestCase
{
    use RefreshDatabase;

    private function insertSettlement(): int
    {
        return DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_test_' . uniqid(),
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
    }

    // ── Settlements ──────────────────────────────────────────────────────────

    public function test_admin_can_list_settlements(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->insertSettlement();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/settlements')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_non_admin_cannot_list_settlements(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->getJson('/api/v1/admin/settlements')
            ->assertForbidden();
    }

    public function test_admin_can_view_settlement_with_lines(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->insertSettlement();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/settlements/{$id}")
            ->assertOk()
            ->assertJsonStructure(['settlement', 'lines']);
    }

    // ── Reconciliation ───────────────────────────────────────────────────────

    public function test_admin_can_trigger_reconciliation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/reconciliation-runs', [
                'period_start'             => '2026-10-01',
                'period_end'               => '2026-10-07',
                'stripe_received_cents'    => 0,
                'stripe_transferred_cents' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'matched');
    }

    public function test_non_admin_cannot_trigger_reconciliation(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/admin/reconciliation-runs', [
                'period_start'             => '2026-10-01',
                'period_end'               => '2026-10-07',
                'stripe_received_cents'    => 0,
                'stripe_transferred_cents' => 0,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_list_reconciliation_runs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-runs')
            ->assertOk();
    }
}
