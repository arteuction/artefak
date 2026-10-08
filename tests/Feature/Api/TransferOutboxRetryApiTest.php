<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TransferOutboxRetryApiTest extends TestCase
{
    use RefreshDatabase;

    private function insertOutboxRow(string $status): int
    {
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_test_' . uniqid(),
            'stripe_event_id'          => 'evt_test_' . uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'BGN',
            'profile_key'              => 'default',
            'profile_version'          => 1,
            'artist_bps'               => 8000,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 1000,
            'artist_cents'             => 8000,
            'fund_cents'               => 1000,
            'ops_cents'                => 1000,
            'status'                   => 'pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id'  => $settlementId,
            'recipient_type' => 'artist',
            'amount_cents'   => 10000,
            'currency'       => 'BGN',
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return (int) DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $lineId,
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 10000,
            'currency'               => 'BGN',
            'stripe_idempotency_key' => 'idem_' . uniqid(),
            'status'                 => $status,
            'attempt'                => 1,
            'last_error'             => $status === 'failed' ? 'stripe timeout' : null,
            'next_attempt_at'        => now()->subMinutes(5),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function test_admin_can_retry_failed_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->insertOutboxRow('failed');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ops/transfer-outbox/{$id}/retry")
            ->assertOk()
            ->assertJsonFragment(['message' => "Row #{$id} reset to pending for retry."]);

        $row = DB::table('transfer_outbox')->find($id);
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->last_error);
        $this->assertStringContainsString('_retry_', $row->stripe_idempotency_key);
    }

    public function test_retry_rejected_when_row_is_not_failed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->insertOutboxRow('pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ops/transfer-outbox/{$id}/retry")
            ->assertStatus(422);
    }

    public function test_retry_returns_404_for_missing_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ops/transfer-outbox/99999/retry')
            ->assertNotFound();
    }

    public function test_non_admin_cannot_retry(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $id     = $this->insertOutboxRow('failed');

        $this->actingAs($artist, 'sanctum')
            ->postJson("/api/v1/ops/transfer-outbox/{$id}/retry")
            ->assertForbidden();
    }
}
