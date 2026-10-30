<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ReconciliationMismatch;
use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin reconciliation mismatch endpoints:
 *   GET   /api/v1/admin/reconciliation-mismatches
 *   GET   /api/v1/admin/reconciliation-mismatches/{mismatch}
 *   PATCH /api/v1/admin/reconciliation-mismatches/{mismatch}
 */
final class ReconciliationMismatchApiTest extends TestCase
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

    private function reconciliationRun(): ReconciliationRun
    {
        return ReconciliationRun::create([
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end'   => now()->subDay()->toDateString(),
            'status'       => 'completed',
        ]);
    }

    private function mismatch(ReconciliationRun $reconciliationRun, string $status = 'open'): ReconciliationMismatch
    {
        return ReconciliationMismatch::create([
            'reconciliation_run_id' => $reconciliationRun->id,
            'check_type'            => 'amount_delta',
            'delta_cents'           => 1000,
            'description'           => 'Test mismatch',
            'resolution_status'     => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_mismatches(): void
    {
        $this->getJson('/api/v1/admin/reconciliation-mismatches')->assertStatus(401);
    }

    public function test_buyer_cannot_list_mismatches(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/reconciliation-mismatches')
            ->assertStatus(403);
    }

    public function test_admin_can_list_mismatches(): void
    {
        $run = $this->reconciliationRun();
        $this->mismatch($run);
        $this->mismatch($run);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-mismatches')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_resolution_status(): void
    {
        $run = $this->reconciliationRun();
        $this->mismatch($run, 'open');
        $this->mismatch($run, 'resolved');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-mismatches?resolution_status=open')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('open', $response->json('data.0.resolution_status'));
    }

    public function test_index_can_filter_by_reconciliation_run_id(): void
    {
        $run1 = $this->reconciliationRun();
        $run2 = $this->reconciliationRun();
        $this->mismatch($run1);
        $this->mismatch($run2);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/reconciliation-mismatches?reconciliation_run_id={$run1->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_mismatch(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run);

        $this->getJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}")
            ->assertStatus(401);
    }

    public function test_admin_can_show_mismatch(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}")
            ->assertOk();

        $this->assertSame($mismatch->id, $response->json('data.id'));
        $this->assertSame('amount_delta', $response->json('data.check_type'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reconciliation-mismatches/99999999')
            ->assertStatus(404);
    }

    // ── update (resolution lifecycle) ─────────────────────────────────────────

    public function test_unauthenticated_cannot_update_mismatch(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run);

        $this->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [])
            ->assertStatus(401);
    }

    public function test_admin_can_transition_open_to_investigating(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run, 'open');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'investigating',
            ])
            ->assertOk();

        $this->assertSame('investigating', $response->json('data.resolution_status'));
    }

    public function test_admin_can_transition_investigating_to_resolved(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run, 'investigating');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'resolved',
                'resolution_notes'  => 'Manual reconciliation applied.',
            ])
            ->assertOk();

        $this->assertSame('resolved', $response->json('data.resolution_status'));
        $this->assertNotNull($response->json('data.resolved_at'));
    }

    public function test_admin_can_suppress_open_mismatch(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run, 'open');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [
                'resolution_status' => 'suppressed',
            ])
            ->assertOk();

        $this->assertSame('suppressed', $response->json('data.resolution_status'));
    }

    public function test_update_validates_required_resolution_status(): void
    {
        $run      = $this->reconciliationRun();
        $mismatch = $this->mismatch($run, 'open');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/reconciliation-mismatches/{$mismatch->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resolution_status']);
    }
}
