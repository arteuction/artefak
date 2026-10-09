<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ReconciliationMismatch;
use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 60: Admin reconciliation mismatch list + resolution API.
 */
final class Phase60ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeRun(): ReconciliationRun
    {
        return ReconciliationRun::create([
            'period_start'            => '2026-01-01',
            'period_end'              => '2026-01-31',
            'stripe_received_cents'   => 100_000,
            'stripe_transferred_cents'=> 50_000,
            'status'                  => 'mismatched',
            'mismatch_count'          => 1,
        ]);
    }

    private function makeMismatch(ReconciliationRun $run, array $overrides = []): ReconciliationMismatch
    {
        return ReconciliationMismatch::create(array_merge([
            'reconciliation_run_id' => $run->id,
            'check_type'            => 'payment',
            'stripe_transaction_id' => 'ch_test_' . uniqid(),
            'internal_reference'    => null,
            'stripe_amount_cents'   => 5_000,
            'internal_amount_cents' => null,
            'delta_cents'           => 5_000,
            'description'           => 'Missing internal settlement for Stripe charge.',
            'resolution_status'     => 'open',
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_admin_can_list_all_mismatches(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run   = $this->makeRun();
        $this->makeMismatch($run, ['check_type' => 'payment']);
        $this->makeMismatch($run, ['check_type' => 'refund']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-mismatches')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_admin_can_filter_mismatches_by_check_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run   = $this->makeRun();
        $this->makeMismatch($run, ['check_type' => 'payment']);
        $this->makeMismatch($run, ['check_type' => 'refund']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-mismatches?check_type=refund')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('refund', $response->json('data.0.check_type'));
    }

    public function test_admin_can_filter_mismatches_by_resolution_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run   = $this->makeRun();
        $this->makeMismatch($run, ['resolution_status' => 'open']);
        $this->makeMismatch($run, ['resolution_status' => 'resolved']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-mismatches?resolution_status=open')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('open', $response->json('data.0.resolution_status'));
    }

    public function test_admin_can_filter_mismatches_by_run_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run1  = $this->makeRun();
        $run2  = $this->makeRun();
        $this->makeMismatch($run1);
        $this->makeMismatch($run2);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/reconciliation-mismatches?reconciliation_run_id={$run1->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($run1->id, $response->json('data.0.reconciliation_run_id'));
    }

    public function test_non_admin_cannot_list_mismatches(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-mismatches')
            ->assertForbidden();
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_admin_can_show_single_mismatch_with_run(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}")
            ->assertOk();

        $this->assertSame($mismatch->id, $response->json('data.id'));
        $this->assertNotNull($response->json('data.run'));
        $this->assertSame($run->id, $response->json('data.run.id'));
    }

    // ── Update (resolution lifecycle) ─────────────────────────────────────────

    public function test_admin_can_move_mismatch_to_investigating(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run, ['resolution_status' => 'open']);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'investigating',
                'resolution_notes'  => 'Checking Stripe dashboard for this charge.',
            ])
            ->assertOk();

        $this->assertSame('investigating', $response->json('data.resolution_status'));
        $this->assertSame('Checking Stripe dashboard for this charge.', $response->json('data.resolution_notes'));
        $this->assertDatabaseHas('reconciliation_mismatches', [
            'id'                => $mismatch->id,
            'resolution_status' => 'investigating',
        ]);
    }

    public function test_admin_can_resolve_mismatch_and_resolved_at_is_set(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run, ['resolution_status' => 'investigating']);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'resolved',
                'resolution_notes'  => 'Confirmed: charge was for a test transaction, already reversed.',
            ])
            ->assertOk();

        $this->assertSame('resolved', $response->json('data.resolution_status'));
        $this->assertNotNull($response->json('data.resolved_at'));
    }

    public function test_admin_can_suppress_mismatch(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run, ['resolution_status' => 'open']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'suppressed',
                'resolution_notes'  => 'Known limitation in period boundary; suppressing.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'suppressed');
    }

    public function test_cannot_reopen_resolved_mismatch(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run, ['resolution_status' => 'resolved']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'investigating',
            ])
            ->assertUnprocessable();
    }

    public function test_update_requires_valid_resolution_status(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $run      = $this->makeRun();
        $mismatch = $this->makeMismatch($run);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'open', // 'open' not allowed via PATCH (can't go back to open)
            ])
            ->assertUnprocessable();
    }
}
