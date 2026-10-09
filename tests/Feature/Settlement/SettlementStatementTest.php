<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 86 — Settlement PDF statement tests.
 */
final class SettlementStatementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function seedSettlement(): int
    {
        return DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_test_' . uniqid(),
            'stripe_event_id'          => 'evt_test_' . uniqid(),
            'gross_cents'              => 50000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 22500,
            'fund_cents'               => 22500,
            'ops_cents'                => 5000,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    public function test_statement_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/settlements/1/statement')->assertUnauthorized();
    }

    public function test_non_admin_cannot_download_statement(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $id    = $this->seedSettlement();

        $this->actingAs($buyer)
            ->get("/api/v1/admin/settlements/{$id}/statement")
            ->assertForbidden();
    }

    public function test_admin_can_download_settlement_statement_as_pdf(): void
    {
        $admin = $this->admin();
        $id    = $this->seedSettlement();

        $response = $this->actingAs($admin)
            ->get("/api/v1/admin/settlements/{$id}/statement");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf',
            $response->headers->get('Content-Type'));
    }

    public function test_statement_returns_404_for_missing_settlement(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/api/v1/admin/settlements/999999/statement')
            ->assertNotFound();
    }

    public function test_generated_pdf_contains_payment_intent_id(): void
    {
        $admin = $this->admin();
        $id    = $this->seedSettlement();

        $settlement = DB::table('settlements')->find($id);

        $response = $this->actingAs($admin)
            ->get("/api/v1/admin/settlements/{$id}/statement");

        $response->assertOk();

        // The Content-Disposition header must name the file after the payment intent
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString($settlement->stripe_payment_intent_id, $disposition);
    }
}
