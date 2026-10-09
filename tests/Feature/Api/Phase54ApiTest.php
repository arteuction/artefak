<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Events\AdminOperationalAlert;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 54: Operational alerts — broadcast service and anomaly checks.
 */
final class Phase54ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── OperationalAlertService ───────────────────────────────────────────────

    public function test_alert_service_fires_admin_operational_alert_event(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $service = app(OperationalAlertService::class);
        $service->alert('test_alert', 'Something went wrong', ['count' => 3]);

        Event::assertDispatched(AdminOperationalAlert::class, function ($e): bool {
            return $e->alertType === 'test_alert'
                && $e->message === 'Something went wrong'
                && $e->context['count'] === 3;
        });
    }

    public function test_admin_operational_alert_broadcasts_on_admin_channel(): void
    {
        $event = new AdminOperationalAlert('transfer_failed', 'Two transfers failed', ['count' => 2]);

        $channel = $event->broadcastOn();
        $this->assertStringContainsString('admin', $channel->name);
        $this->assertSame('admin.alert', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame('transfer_failed', $payload['alert_type']);
        $this->assertSame('Two transfers failed', $payload['message']);
        $this->assertArrayHasKey('fired_at', $payload);
    }

    // ── CheckOperationalAlerts command ────────────────────────────────────────

    public function test_alert_service_context_is_included_in_broadcast_payload(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $service = app(OperationalAlertService::class);
        $service->alert('transfer_failed', '2 transfers failed', ['count' => 2, 'ids' => [1, 2]]);

        Event::assertDispatched(AdminOperationalAlert::class, function ($e): bool {
            return $e->alertType === 'transfer_failed'
                && $e->context['count'] === 2
                && $e->context['ids'] === [1, 2];
        });
    }

    public function test_check_command_fires_alert_for_stuck_domain_events(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        DB::table('domain_events')->insert([
            'aggregate_type'  => 'AuctionItem',
            'aggregate_id'    => 1,
            'event_type'      => 'auction_item.stuck',
            'event_version'   => 1,
            'payload'         => '{}',
            'idempotency_key' => 'test-stuck-' . uniqid(),
            'status'          => 'pending',
            'created_at'      => now()->subMinutes(15),
            'updated_at'      => now()->subMinutes(15),
        ]);

        $this->artisan('arteuction:check-operational-alerts')->assertSuccessful();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e): bool {
            return $e->alertType === 'domain_event_stuck';
        });
    }

    public function test_check_command_fires_alert_for_failed_domain_events(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        DB::table('domain_events')->insert([
            'aggregate_type'  => 'AuctionItem',
            'aggregate_id'    => 2,
            'event_type'      => 'auction_item.failed',
            'event_version'   => 1,
            'payload'         => '{}',
            'idempotency_key' => 'test-failed-' . uniqid(),
            'status'          => 'failed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->artisan('arteuction:check-operational-alerts')->assertSuccessful();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e): bool {
            return $e->alertType === 'domain_event_failed';
        });
    }

    public function test_check_command_fires_no_alert_when_all_healthy(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $this->artisan('arteuction:check-operational-alerts')->assertSuccessful();

        Event::assertNotDispatched(AdminOperationalAlert::class);
    }
}
