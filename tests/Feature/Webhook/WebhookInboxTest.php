<?php

namespace Tests\Feature\Webhook;

use App\Jobs\HandleStripeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_dummy';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => $this->secret]);
    }

    private function sign(string $payload): string
    {
        $t   = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $this->secret);
        return "t={$t},v1={$sig}";
    }

    private function webhook(string $payload, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            method: 'POST',
            uri: '/stripe/webhook',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE'          => 'application/json',
            ],
            content: $payload,
        );
    }

    private function event(string $type, string $id, array $object = []): string
    {
        return json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
    }

    // ── signature ─────────────────────────────────────────────────────────────

    public function test_valid_signature_stores_event_and_returns_200(): void
    {
        Queue::fake();
        $payload = $this->event('payment_intent.payment_failed', 'evt_001');

        $this->webhook($payload, $this->sign($payload))->assertStatus(200);

        $this->assertDatabaseHas('webhook_events', [
            'stripe_event_id' => 'evt_001',
            'type'            => 'payment_intent.payment_failed',
            'status'          => 'received',
        ]);
        Queue::assertPushed(HandleStripeWebhook::class, 1);
    }

    public function test_invalid_signature_returns_400_and_stores_nothing(): void
    {
        Queue::fake();
        $payload = $this->event('payment_intent.payment_failed', 'evt_002');

        $this->webhook($payload, 't=1234567890,v1=badsignature')->assertStatus(400);

        $this->assertDatabaseMissing('webhook_events', ['stripe_event_id' => 'evt_002']);
        Queue::assertNothingPushed();
    }

    // ── idempotency ───────────────────────────────────────────────────────────

    public function test_duplicate_event_returns_200_without_second_row(): void
    {
        Queue::fake();
        $payload = $this->event('payment_intent.payment_failed', 'evt_003');
        $sig     = $this->sign($payload);

        $this->webhook($payload, $sig)->assertStatus(200);
        $this->webhook($payload, $sig)->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('webhook_events')->where('stripe_event_id', 'evt_003')->count(),
        );
        Queue::assertPushed(HandleStripeWebhook::class, 1);
    }

    // ── routing ───────────────────────────────────────────────────────────────

    public function test_charge_refunded_event_is_accepted_and_queued(): void
    {
        Queue::fake();

        $payload = json_encode([
            'id'   => 'evt_004',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id'             => 'ch_test',
                    'payment_intent' => 'pi_test',
                    'refunds'        => [
                        'data' => [
                            ['id' => 're_001', 'amount' => 5000, 'currency' => 'eur'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->webhook($payload, $this->sign($payload))->assertStatus(200);

        $this->assertDatabaseHas('webhook_events', [
            'stripe_event_id' => 'evt_004',
            'type'            => 'charge.refunded',
            'status'          => 'received',
        ]);
        Queue::assertPushed(HandleStripeWebhook::class, 1);
    }
}
