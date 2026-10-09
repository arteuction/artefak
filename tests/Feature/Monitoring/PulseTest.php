<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 90 — Laravel Pulse operational monitoring.
 *
 * Verifies the /pulse dashboard is gate-protected and only accessible
 * to users with the admin role.
 */
final class PulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_dashboard_requires_authentication(): void
    {
        // Pulse Authorize middleware returns 403 for guests (no auth redirect).
        $this->getJson('/pulse')->assertForbidden();
    }

    public function test_pulse_dashboard_is_forbidden_for_non_admin(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist)->get('/pulse')->assertForbidden();
    }

    public function test_pulse_dashboard_is_accessible_to_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/pulse')->assertOk();
    }

    public function test_pulse_migrations_exist(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('pulse_entries'),
            'pulse_entries table must exist after migrations.',
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('pulse_aggregates'),
            'pulse_aggregates table must exist after migrations.',
        );
    }
}
