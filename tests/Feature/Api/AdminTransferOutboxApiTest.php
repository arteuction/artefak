<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for admin transfer outbox endpoints:
 *   GET /api/v1/admin/transfer-outbox
 *   GET /api/v1/admin/transfer-outbox/{id}
 */
final class AdminTransferOutboxApiTest extends TestCase
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

    private function outboxRow(string $status = 'pending'): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'    => 1,
            'stripe_account_id'     => 'acct_test_' . uniqid(),
            'stripe_idempotency_key' => 'idem_' . uniqid(),
            'amount_cents'          => 5000,
            'currency'              => 'EUR',
            'status'                => $status,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/transfer-outbox')->assertStatus(401);
    }

    public function test_buyer_cannot_access_transfer_outbox(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertStatus(403);
    }

    public function test_admin_can_list_transfer_outbox(): void
    {
        $this->outboxRow('pending');
        $this->outboxRow('dispatched');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('total', $response->json());
        $this->assertArrayHasKey('summary', $response->json());
        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $this->outboxRow('pending');
        $this->outboxRow('failed');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/transfer-outbox?status=failed')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/transfer-outbox/1')->assertStatus(401);
    }

    public function test_admin_can_show_outbox_row(): void
    {
        $id = $this->outboxRow('dispatched');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/transfer-outbox/{$id}")
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertSame($id, $response->json('data.id'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/transfer-outbox/99999999')
            ->assertStatus(404);
    }
}
