<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 61: Admin Ruleset management + Transfer Outbox read view.
 */
final class Phase61ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── AuctionRuleset CRUD ───────────────────────────────────────────────────

    public function test_admin_can_list_rulesets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AuctionRuleset::create(['name' => 'Standard']);
        AuctionRuleset::create(['name' => 'Premium']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/rulesets')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Standard', $names);
        $this->assertContains('Premium', $names);
    }

    public function test_non_admin_cannot_list_rulesets(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/rulesets')
            ->assertForbidden();
    }

    public function test_admin_can_create_ruleset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/rulesets', [
                'name'                        => 'Charity Auction Ruleset',
                'default_bid_increment_cents' => 500,
                'anti_sniping_seconds'        => 60,
                'extension_seconds'           => 60,
                'proxy_bid_enabled'           => true,
                'reserve_enabled'             => true,
                'counter_offer_enabled'       => false,
                'tie_policy'                  => 'first_wins',
            ])
            ->assertCreated();

        $this->assertSame('Charity Auction Ruleset', $response->json('data.name'));
        $this->assertTrue($response->json('data.proxy_bid_enabled'));
        $this->assertSame(500, $response->json('data.default_bid_increment_cents'));

        $this->assertDatabaseHas('auction_rulesets', ['name' => 'Charity Auction Ruleset']);
    }

    public function test_create_ruleset_validates_name_required(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/rulesets', [
                'default_bid_increment_cents' => 500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_show_single_ruleset(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $ruleset = AuctionRuleset::create(['name' => 'Show Me']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/rulesets/{$ruleset->id}")
            ->assertOk();

        $this->assertSame($ruleset->id, $response->json('data.id'));
        $this->assertSame('Show Me', $response->json('data.name'));
    }

    public function test_admin_can_update_ruleset_not_attached_to_live_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $ruleset = AuctionRuleset::create(['name' => 'Old Name', 'anti_sniping_seconds' => 60]);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/rulesets/{$ruleset->id}", [
                'name'                 => 'Updated Name',
                'anti_sniping_seconds' => 180,
            ])
            ->assertOk();

        $this->assertSame('Updated Name', $response->json('data.name'));
        $this->assertSame(180, $response->json('data.anti_sniping_seconds'));
    }

    public function test_cannot_update_ruleset_attached_to_live_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $ruleset = AuctionRuleset::create(['name' => 'Live Ruleset']);

        // Attach to a live auction
        DB::table('auctions')->insert([
            'title'      => 'Live Auction',
            'slug'       => 'live-auction-' . uniqid(),
            'status'     => 'live',
            'currency'   => 'EUR',
            'ruleset_id' => $ruleset->id,
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/rulesets/{$ruleset->id}", [
                'name' => 'Cannot Change This',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'live or closed'));
    }

    // ── Transfer Outbox read view ─────────────────────────────────────────────

    private function seedOutboxRow(string $status = 'pending'): int
    {
        // settlement_line_id is NOT NULL FK — create a minimal settlement + line first
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'status'                   => 'completed',
            'gross_cents'              => 10_000,
            'currency'                 => 'EUR',
            'profile_key'              => 'standard',
            'profile_version'          => 1,
            'artist_bps'               => 8500,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 500,
            'artist_cents'             => 8_500,
            'fund_cents'               => 1_000,
            'ops_cents'                => 500,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id'   => $settlementId,
            'recipient_type'  => 'artist',
            'amount_cents'    => 10_000,
            'weight'          => 10000,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'      => $lineId,
            'stripe_account_id'       => 'acct_' . uniqid(),
            'stripe_idempotency_key'  => 'idem_' . uniqid(),
            'amount_cents'            => 10_000,
            'currency'                => 'EUR',
            'status'                  => $status,
            'attempt'                 => 0,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    public function test_admin_can_list_transfer_outbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seedOutboxRow('pending');
        $this->seedOutboxRow('failed');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertOk();

        $this->assertGreaterThanOrEqual(2, $response->json('total'));
        $this->assertArrayHasKey('summary', $response->json());
    }

    public function test_admin_can_filter_outbox_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seedOutboxRow('pending');
        $this->seedOutboxRow('failed');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox?status=failed')
            ->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertSame('failed', $row['status']);
        }
    }

    public function test_admin_can_show_single_transfer_outbox_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->seedOutboxRow('dispatched');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/transfer-outbox/{$id}")
            ->assertOk();

        $this->assertSame($id, $response->json('data.id'));
    }

    public function test_transfer_outbox_show_returns_404_for_missing_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox/99999999')
            ->assertNotFound();
    }

    public function test_non_admin_cannot_view_transfer_outbox(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/transfer-outbox')
            ->assertForbidden();
    }
}
