<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ImpactEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for impact event endpoints (public read-only):
 *   GET /api/v1/impact-events
 *   GET /api/v1/impact-events/{impactEvent}
 */
final class ImpactEventApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function impactEvent(int $sdg = 13, string $metric = 'trees_planted'): ImpactEvent
    {
        return ImpactEvent::create([
            'sdg_number'    => $sdg,
            'metric'        => $metric,
            'value'         => 10,
            'description'   => 'Test event.',
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_impact_events(): void
    {
        $this->getJson('/api/v1/impact-events')->assertStatus(401);
    }

    public function test_buyer_can_list_impact_events(): void
    {
        $this->impactEvent();
        $this->impactEvent(7, 'kwh_saved');

        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/impact-events')
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_index_can_filter_by_sdg_number(): void
    {
        $this->impactEvent(7);
        $this->impactEvent(13);

        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/impact-events?sdg_number=7')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(7, $response->json('data.data.0.sdg_number'));
    }

    public function test_index_can_filter_by_metric(): void
    {
        $this->impactEvent(13, 'trees_planted');
        $this->impactEvent(7, 'kwh_saved');

        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/impact-events?metric=trees_planted')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_impact_event(): void
    {
        $event = $this->impactEvent();
        $this->getJson("/api/v1/impact-events/{$event->id}")->assertStatus(401);
    }

    public function test_buyer_can_show_impact_event(): void
    {
        $event = $this->impactEvent(7, 'kwh_saved');

        $response = $this->actingAs($this->buyer())
            ->getJson("/api/v1/impact-events/{$event->id}")
            ->assertOk();

        $this->assertSame($event->id, $response->json('data.id'));
        $this->assertSame(7, $response->json('data.sdg_number'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/impact-events/99999999')
            ->assertStatus(404);
    }
}
