<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\HandleStripeWebhook;
use App\Models\ConnectedAccountPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 48: Connected-account payout lifecycle (payout.paid/failed/canceled webhook handlers)
 * and admin read-only view of connected payouts.
 */
final class Phase48ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Webhook routing ───────────────────────────────────────────────────────

    private function makeWebhookEvent(string $type, string $payoutId, string $accountId, string $status): int
    {
        $payload = json_encode([
            'id'      => 'evt_' . uniqid(),
            'type'    => $type,
            'account' => $accountId,
            'data'    => [
                'object' => [
                    'id'              => $payoutId,
                    'object'          => 'payout',
                    'amount'          => 50000,
                    'currency'        => 'bgn',
                    'status'          => $status,
                    'arrival_date'    => now()->addDays(2)->timestamp,
                    'failure_code'    => $status === 'failed' ? 'insufficient_funds' : null,
                    'failure_message' => $status === 'failed' ? 'The account has insufficient funds.' : null,
                ],
            ],
        ]);

        return DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_' . uniqid(),
            'type'            => $type,
            'payload'         => $payload,
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_payout_paid_webhook_creates_payout_record(): void
    {
        $payoutId  = 'po_' . uniqid();
        $accountId = 'acct_' . uniqid();
        $eventId   = $this->makeWebhookEvent('payout.paid', $payoutId, $accountId, 'paid');

        (new HandleStripeWebhook($eventId))->handle(
            app(\App\Application\Settlement\ProcessRefund::class),
            app(\App\Domain\Library\FinalizePaidBookPurchase::class),
            app(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            app(\App\Domain\Auction\SettleAuction::class),
        );

        $this->assertDatabaseHas('connected_account_payouts', [
            'stripe_payout_id'  => $payoutId,
            'stripe_account_id' => $accountId,
            'status'            => 'paid',
        ]);
        $this->assertDatabaseHas('webhook_events', ['id' => $eventId, 'status' => 'processed']);
    }

    public function test_payout_failed_webhook_records_failure_details(): void
    {
        $payoutId  = 'po_' . uniqid();
        $accountId = 'acct_' . uniqid();
        $eventId   = $this->makeWebhookEvent('payout.failed', $payoutId, $accountId, 'failed');

        (new HandleStripeWebhook($eventId))->handle(
            app(\App\Application\Settlement\ProcessRefund::class),
            app(\App\Domain\Library\FinalizePaidBookPurchase::class),
            app(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            app(\App\Domain\Auction\SettleAuction::class),
        );

        $this->assertDatabaseHas('connected_account_payouts', [
            'stripe_payout_id' => $payoutId,
            'status'           => 'failed',
            'failure_code'     => 'insufficient_funds',
        ]);
    }

    public function test_payout_canceled_webhook_records_canceled_status(): void
    {
        $payoutId  = 'po_' . uniqid();
        $accountId = 'acct_' . uniqid();
        $eventId   = $this->makeWebhookEvent('payout.canceled', $payoutId, $accountId, 'canceled');

        (new HandleStripeWebhook($eventId))->handle(
            app(\App\Application\Settlement\ProcessRefund::class),
            app(\App\Domain\Library\FinalizePaidBookPurchase::class),
            app(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            app(\App\Domain\Auction\SettleAuction::class),
        );

        $this->assertDatabaseHas('connected_account_payouts', [
            'stripe_payout_id' => $payoutId,
            'status'           => 'canceled',
        ]);
    }

    public function test_duplicate_payout_webhook_is_idempotent(): void
    {
        $payoutId  = 'po_' . uniqid();
        $accountId = 'acct_' . uniqid();

        // Process same payout twice via two separate webhook events
        $eventId1 = $this->makeWebhookEvent('payout.paid', $payoutId, $accountId, 'paid');
        $eventId2 = $this->makeWebhookEvent('payout.paid', $payoutId, $accountId, 'paid');

        $handler = app(\App\Domain\Payment\HandleConnectedAccountPayout::class);
        $refund  = app(\App\Application\Settlement\ProcessRefund::class);
        $book    = app(\App\Domain\Library\FinalizePaidBookPurchase::class);

        $settle = app(\App\Domain\Auction\SettleAuction::class);
        (new HandleStripeWebhook($eventId1))->handle($refund, $book, $handler, $settle);
        (new HandleStripeWebhook($eventId2))->handle($refund, $book, $handler, $settle);

        $this->assertSame(1, ConnectedAccountPayout::where('stripe_payout_id', $payoutId)->count());
    }

    public function test_payout_event_without_account_is_silently_skipped(): void
    {
        // Platform-level payout events do not include 'account' — we skip them.
        $payload = json_encode([
            'id'   => 'evt_' . uniqid(),
            'type' => 'payout.paid',
            // no 'account' key
            'data' => ['object' => ['id' => 'po_' . uniqid(), 'amount' => 1000, 'currency' => 'bgn']],
        ]);

        $eventId = DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_' . uniqid(),
            'type'            => 'payout.paid',
            'payload'         => $payload,
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        (new HandleStripeWebhook($eventId))->handle(
            app(\App\Application\Settlement\ProcessRefund::class),
            app(\App\Domain\Library\FinalizePaidBookPurchase::class),
            app(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            app(\App\Domain\Auction\SettleAuction::class),
        );

        $this->assertSame(0, ConnectedAccountPayout::count());
        $this->assertDatabaseHas('webhook_events', ['id' => $eventId, 'status' => 'processed']);
    }

    // ── Admin HTTP endpoints ──────────────────────────────────────────────────

    private function seedPayout(string $status = 'paid'): ConnectedAccountPayout
    {
        return ConnectedAccountPayout::create([
            'stripe_payout_id'  => 'po_' . uniqid(),
            'stripe_account_id' => 'acct_' . uniqid(),
            'stripe_event_id'   => 'evt_' . uniqid(),
            'amount_cents'      => 50000,
            'currency'          => 'BGN',
            'status'            => $status,
        ]);
    }

    public function test_admin_can_list_connected_payouts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seedPayout('paid');
        $this->seedPayout('failed');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/connected-payouts')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'summary']);
    }

    public function test_admin_can_filter_connected_payouts_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seedPayout('paid');
        $this->seedPayout('failed');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/connected-payouts?status=failed')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    public function test_admin_can_show_single_connected_payout(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $payout = $this->seedPayout('paid');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/connected-payouts/{$payout->id}")
            ->assertOk()
            ->assertJsonPath('data.stripe_payout_id', $payout->stripe_payout_id);
    }

    public function test_non_admin_cannot_view_connected_payouts(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/connected-payouts')
            ->assertForbidden();
    }
}
