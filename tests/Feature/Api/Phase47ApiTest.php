<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 47: Admin Auction Ruleset CRUD, Transfer Outbox admin view.
 */
final class Phase47ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Auction Rulesets ──────────────────────────────────────────────────────

    public function test_admin_can_create_ruleset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/rulesets', [
                'name'                        => 'Standard Auction',
                'default_bid_increment_cents' => 1000,
                'anti_sniping_seconds'        => 120,
                'reserve_enabled'             => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Standard Auction');
    }

    public function test_admin_can_list_rulesets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AuctionRuleset::create(['name' => 'Ruleset A']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/rulesets')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_admin_can_update_ruleset_without_live_auctions(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $ruleset = AuctionRuleset::create(['name' => 'Old Name']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/rulesets/{$ruleset->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_cannot_update_ruleset_with_live_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $ruleset = AuctionRuleset::create(['name' => 'Live Ruleset']);

        Auction::create([
            'title'      => 'Live Auction',
            'slug'       => 'live-' . uniqid(),
            'ruleset_id' => $ruleset->id,
            'status'     => 'live',
            'currency'   => 'BGN',
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addHour(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/rulesets/{$ruleset->id}", ['name' => 'Changed'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_create_ruleset(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/admin/rulesets', ['name' => 'Sneak'])
            ->assertForbidden();
    }

    // ── Transfer Outbox ───────────────────────────────────────────────────────

    private function makeTransferOutboxRow(int $lineId): int
    {
        return DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'      => $lineId,
            'stripe_account_id'       => 'acct_' . uniqid(),
            'stripe_idempotency_key'  => 'ik_' . uniqid(),
            'amount_cents'            => 5000,
            'currency'                => 'BGN',
            'status'                  => 'pending',
            'attempt'                 => 0,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    private function makeSettlementLine(): int
    {
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents' => 10000, 'currency' => 'BGN',
            'profile_key' => 'default', 'profile_version' => 1,
            'artist_bps' => 7500, 'fund_bps' => 1000, 'ops_bps' => 1500,
            'artist_cents' => 7500, 'fund_cents' => 1000, 'ops_cents' => 1500,
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('settlement_lines')->insertGetId([
            'settlement_id' => $settlementId, 'recipient_type' => 'artist',
            'amount_cents' => 7500, 'currency' => 'BGN', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_admin_can_view_transfer_outbox(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $lineId = $this->makeSettlementLine();
        $this->makeTransferOutboxRow($lineId);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'summary']);
    }

    public function test_admin_can_view_single_outbox_row(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $lineId = $this->makeSettlementLine();
        $rowId  = $this->makeTransferOutboxRow($lineId);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/transfer-outbox/{$rowId}")
            ->assertOk()
            ->assertJsonStructure(['data', 'settlement_line']);
    }

    public function test_non_admin_cannot_view_transfer_outbox(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertForbidden();
    }
}
