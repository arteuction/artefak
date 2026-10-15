<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\HandleStripeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fault-injection tests for HandleStripeWebhook.
 *
 * These cover the failure modes that WebhookInboxTest doesn't address:
 * worker crashes / exceptions during processing, orphaned-status recovery,
 * and idempotent retry semantics.
 */
final class HandleStripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function insertEvent(string $type, string $stripeId, string $status = 'received'): int
    {
        return (int) DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => $stripeId,
            'type'            => $type,
            'payload'         => json_encode([
                'id'   => $stripeId,
                'type' => $type,
                'data' => ['object' => []],
            ]),
            'status'          => $status,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ── Normal processing ─────────────────────────────────────────────────────

    public function test_received_event_is_marked_processed(): void
    {
        $id = $this->insertEvent('payment_intent.payment_failed', 'evt_proc_01');

        $job = new HandleStripeWebhook($id);
        $job->handle(
            $this->app->make(\App\Application\Settlement\ProcessRefund::class),
            $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
            $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            $this->app->make(\App\Domain\Auction\SettleAuction::class),
            $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
            $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
        );

        $this->assertSame('processed', DB::table('webhook_events')->find($id)->status);
    }

    // ── Exception / status-reset guard ───────────────────────────────────────

    public function test_exception_during_routing_resets_status_to_received(): void
    {
        // Insert an event with a type that will trigger a handler.
        // We use 'charge.refunded' with a payload that will cause ProcessRefund
        // to short-circuit gracefully (no matching settlement → no exception),
        // but we inject a domain dependency that throws.
        $id = $this->insertEvent('payment_intent.payment_failed', 'evt_exc_01');

        // Replace ProcessRefund with a throwing stub
        $throwingRefund = new class extends \App\Application\Settlement\ProcessRefund {
            public function execute(...$args): int
            {
                throw new \RuntimeException('Injected fault for testing');
            }
        };

        // Use a payload that routes to 'charge.refunded' to trigger our stub
        DB::table('webhook_events')->where('id', $id)->update([
            'type'    => 'charge.refunded',
            'payload' => json_encode([
                'id'   => 'evt_exc_01',
                'type' => 'charge.refunded',
                'data' => ['object' => [
                    'id'              => 'ch_test',
                    'payment_intent'  => 'pi_test',
                    'refunds'         => ['data' => [
                        ['id' => 're_001', 'amount' => 5000, 'currency' => 'eur', 'status' => 'succeeded'],
                    ]],
                ]],
            ]),
        ]);

        $job = new HandleStripeWebhook($id);

        try {
            $job->handle(
                $throwingRefund,
                $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
                $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
                $this->app->make(\App\Domain\Auction\SettleAuction::class),
                $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
                $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
            );
            $this->fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected fault for testing', $e->getMessage());
        }

        $row = DB::table('webhook_events')->find($id);
        $this->assertSame('received', $row->status,
            'Status must be reset to "received" on exception so the next retry attempt can process it.');
        $this->assertNotNull($row->error,
            'Error message should be persisted for ops visibility.');
    }

    public function test_retry_succeeds_after_status_reset(): void
    {
        // Simulate: first attempt threw, status was reset to 'received'.
        // Second attempt (retry) should process normally.
        $id = $this->insertEvent('payment_intent.payment_failed', 'evt_retry_01');

        // First attempt — throws
        $throwingRefund = new class extends \App\Application\Settlement\ProcessRefund {
            public function execute(...$args): int
            {
                throw new \RuntimeException('Transient fault');
            }
        };

        DB::table('webhook_events')->where('id', $id)->update([
            'type'    => 'charge.refunded',
            'payload' => json_encode([
                'id'   => 'evt_retry_01',
                'type' => 'charge.refunded',
                'data' => ['object' => [
                    'id'             => 'ch_retry',
                    'payment_intent' => 'pi_retry',
                    'refunds'        => ['data' => []],  // empty → ProcessRefund loop is a no-op
                ]],
            ]),
        ]);

        try {
            (new HandleStripeWebhook($id))->handle(
                $throwingRefund,
                $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
                $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
                $this->app->make(\App\Domain\Auction\SettleAuction::class),
                $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
                $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
            );
        } catch (\RuntimeException) {
            // Expected
        }
        $this->assertSame('received', DB::table('webhook_events')->find($id)->status);

        // Second attempt — real ProcessRefund, empty refunds array → no-op, should complete
        (new HandleStripeWebhook($id))->handle(
            $this->app->make(\App\Application\Settlement\ProcessRefund::class),
            $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
            $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            $this->app->make(\App\Domain\Auction\SettleAuction::class),
            $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
            $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
        );

        $this->assertSame('processed', DB::table('webhook_events')->find($id)->status,
            'Status must be "processed" after a successful retry.');
    }

    // ── Already-processed guard ───────────────────────────────────────────────

    public function test_already_processed_event_is_not_reprocessed(): void
    {
        $id = $this->insertEvent('payment_intent.payment_failed', 'evt_done_01', 'processed');

        $throwIfCalled = new class extends \App\Application\Settlement\ProcessRefund {
            public function execute(...$args): void
            {
                throw new \RuntimeException('Should not be called — event already processed');
            }
        };

        (new HandleStripeWebhook($id))->handle(
            $throwIfCalled,
            $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
            $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            $this->app->make(\App\Domain\Auction\SettleAuction::class),
            $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
            $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
        );

        $this->assertSame('processed', DB::table('webhook_events')->find($id)->status,
            'Already-processed event must not be reprocessed.');
    }

    public function test_processing_status_blocks_concurrent_retry(): void
    {
        // If a row is stuck in 'processing' (SIGKILL scenario), the guard
        // prevents a concurrent job from running the same event twice.
        $id = $this->insertEvent('payment_intent.payment_failed', 'evt_concurrent_01', 'processing');

        $throwIfCalled = new class extends \App\Application\Settlement\ProcessRefund {
            public function execute(...$args): void
            {
                throw new \RuntimeException('Should not be called — row is still processing');
            }
        };

        (new HandleStripeWebhook($id))->handle(
            $throwIfCalled,
            $this->app->make(\App\Domain\Library\FinalizePaidBookPurchase::class),
            $this->app->make(\App\Domain\Payment\HandleConnectedAccountPayout::class),
            $this->app->make(\App\Domain\Auction\SettleAuction::class),
            $this->app->make(\App\Domain\Fulfillment\ConfirmSellNowPayment::class),
            $this->app->make(\App\Domain\SellNow\CreateSellNowSettlement::class),
        );

        // Still 'processing' — not re-run, not reset
        $this->assertSame('processing', DB::table('webhook_events')->find($id)->status,
            'A "processing" row must not be processed by a concurrent worker.');
    }
}
