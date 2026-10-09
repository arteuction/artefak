<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Settlement\SplitProfile;
use Database\Seeders\PilotDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 83 — Pilot hardening tests.
 *
 * Verifies three production-safety gates:
 *   1. PilotDemoSeeder source contains a production guard
 *   2. refund:create-test command refuses to run in production
 *   3. SplitProfile enforces that all basis-point definitions sum to 10000
 */
final class PilotHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. Demo seeder production guard ──────────────────────────────────────

    public function test_pilot_demo_seeder_contains_production_guard(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(PilotDemoSeeder::class))->getFileName()
        );

        $this->assertStringContainsString("app()->environment('production')", $source,
            'PilotDemoSeeder must check for production environment before seeding.');
        $this->assertStringContainsString('return;', $source,
            'PilotDemoSeeder must return early when blocked.');
    }

    public function test_pilot_demo_seeder_runs_in_non_production(): void
    {
        $this->assertNotEquals('production', app()->environment());

        $this->artisan('db:seed', ['--class' => PilotDemoSeeder::class, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@arteuction.bg']);
    }

    // ── 2. Test-only command production guard ────────────────────────────────

    public function test_refund_create_test_command_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('refund:create-test', [
            'stripe_refund_id'  => 're_test',
            'payment_intent_id' => 'pi_test',
        ])->assertFailed();
    }

    public function test_refund_create_test_command_is_hidden(): void
    {
        $command = $this->app->make(\App\Console\Commands\RefundCreateTestCommand::class);
        $this->assertTrue($command->isHidden());
    }

    // ── 3. SplitProfile basis-point invariant ────────────────────────────────

    public function test_all_split_profiles_sum_to_ten_thousand(): void
    {
        foreach (['social_pilot_45_45_10', 'library_80_10_10'] as $key) {
            $p   = SplitProfile::fromKey($key);
            $sum = $p->artistBps() + $p->fundBps() + $p->operationsBps();
            $this->assertSame(10000, $sum, "Profile '{$key}' sums to {$sum}, not 10000.");
        }
    }

    public function test_split_profile_invariant_guard_is_present_in_source(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(SplitProfile::class))->getFileName()
        );

        $this->assertStringContainsString('LogicException', $source,
            'SplitProfile must throw LogicException for profiles that do not sum to 10000.');
        $this->assertStringContainsString('10000', $source,
            'SplitProfile guard must reference 10000.');
    }
}
