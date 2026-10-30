<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for admin financial visibility endpoints:
 *   GET /api/v1/admin/ledger
 *   GET /api/v1/admin/reconciliation-runs
 *   GET /api/v1/admin/reconciliation-runs/{run}
 *   POST /api/v1/admin/reconciliation-runs
 */
final class LedgerAndReconciliationApiTest extends TestCase
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

    private static int $ledgerSeq = 0;

    private function ledgerEntry(string $type = 'credit', int $amountCents = 10000): void
    {
        self::$ledgerSeq++;
        Schema::disableForeignKeyConstraints();
        DB::table('ledger_entries')->insert([
            'settlement_id'   => 1,
            'type'            => $type,
            'amount_cents'    => $amountCents,
            'currency'        => 'EUR',
            'idempotency_key' => "test:{$type}:" . self::$ledgerSeq,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    private function reconciliationRun(string $status = 'completed'): ReconciliationRun
    {
        return ReconciliationRun::create([
            'period_start'               => now()->subDays(7)->toDateString(),
            'period_end'                 => now()->subDay()->toDateString(),
            'status'                     => $status,
            'internal_gross_cents'       => 100000,
            'internal_transfer_cents'    => 90000,
            'internal_settlement_count'  => 5,
            'stripe_received_cents'      => 100000,
            'stripe_transferred_cents'   => 90000,
            'delta_received_cents'       => 0,
            'delta_transferred_cents'    => 0,
            'mismatch_count'             => 0,
        ]);
    }

    // ── GET /admin/ledger ─────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_ledger(): void
    {
        $this->getJson('/api/v1/admin/ledger')->assertStatus(401);
    }

    public function test_buyer_cannot_view_ledger(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/ledger')
            ->assertStatus(403);
    }

    public function test_admin_can_view_ledger(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/ledger')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'balance_cents', 'total_credits', 'total_debits']);
    }

    public function test_ledger_balance_is_credits_minus_debits(): void
    {
        $this->ledgerEntry('credit', 50000);
        $this->ledgerEntry('credit', 30000);
        $this->ledgerEntry('debit', 20000);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/ledger')
            ->assertOk();

        $this->assertSame(80000, $response->json('total_credits'));
        $this->assertSame(20000, $response->json('total_debits'));
        $this->assertSame(60000, $response->json('balance_cents'));
    }

    public function test_ledger_can_filter_by_type(): void
    {
        $this->ledgerEntry('credit', 50000);
        $this->ledgerEntry('debit', 20000);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/ledger?type=credit')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('credit', $response->json('data.0.type'));
    }

    public function test_ledger_returns_empty_when_no_entries(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/ledger')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame(0, $response->json('balance_cents'));
    }

    // ── GET /admin/reconciliation-runs ────────────────────────────────────────

    public function test_unauthenticated_cannot_list_reconciliation_runs(): void
    {
        $this->getJson('/api/v1/admin/reconciliation-runs')->assertStatus(401);
    }

    public function test_buyer_cannot_list_reconciliation_runs(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/reconciliation-runs')
            ->assertStatus(403);
    }

    public function test_admin_can_list_reconciliation_runs(): void
    {
        $this->reconciliationRun('completed');
        $this->reconciliationRun('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-runs')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_reconciliation_runs_can_filter_by_status(): void
    {
        $this->reconciliationRun('completed');
        $this->reconciliationRun('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-runs?status=failed')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    // ── GET /admin/reconciliation-runs/{run} ──────────────────────────────────

    public function test_unauthenticated_cannot_show_reconciliation_run(): void
    {
        $run = $this->reconciliationRun();

        $this->getJson("/api/v1/admin/reconciliation-runs/{$run->id}")->assertStatus(401);
    }

    public function test_admin_can_show_reconciliation_run(): void
    {
        $run = $this->reconciliationRun('completed');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/reconciliation-runs/{$run->id}")
            ->assertOk();

        $this->assertSame($run->id, $response->json('data.id'));
        $this->assertSame('completed', $response->json('data.status'));
    }

    public function test_show_returns_404_for_unknown_run(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-runs/99999999')
            ->assertStatus(404);
    }
}
