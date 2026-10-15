<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 172 — CulturalDataConformanceCommand registration and smoke tests.
 *
 * The command makes internal HTTP requests to IIIF/EDM/PROV-O/Linked Art
 * endpoints; on an empty database it skips or gracefully degrades those checks.
 * These tests verify registration, exit-code contract, and JSON output shape.
 */
final class CulturalConformanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('cultural:conformance --help')->assertExitCode(0);
    }

    public function test_command_exits_zero_on_empty_database(): void
    {
        // Empty DB → IIIF/PROV-O checks skip, SPDX check passes (no rows).
        $this->artisan('cultural:conformance')->assertExitCode(0);
    }

    public function test_json_format_returns_parseable_json(): void
    {
        $result = $this->artisan('cultural:conformance --format=json');
        $result->assertExitCode(0);

        // Capture output via the underlying Artisan facade
        $output = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('total', $decoded);
        $this->assertArrayHasKey('pass', $decoded);
        $this->assertArrayHasKey('fail', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsInt($decoded['total']);
        $this->assertIsInt($decoded['pass']);
        $this->assertIsInt($decoded['fail']);
        $this->assertIsArray($decoded['checks']);
    }

    public function test_json_output_check_entries_have_required_keys(): void
    {
        \Illuminate\Support\Facades\Artisan::call('cultural:conformance', ['--format' => 'json']);
        $output  = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($output, true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check, 'each check entry needs a "check" label');
            $this->assertArrayHasKey('pass', $check, 'each check entry needs a "pass" boolean');
            $this->assertArrayHasKey('detail', $check, 'each check entry needs a "detail" string');
            $this->assertIsBool($check['pass']);
            $this->assertIsString($check['check']);
        }
    }

    public function test_spdx_check_passes_on_clean_database(): void
    {
        // With no artworks at all, the SPDX regex finds no rows → passes.
        \Illuminate\Support\Facades\Artisan::call('cultural:conformance', ['--format' => 'json']);
        $output  = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($output, true);

        $spdxCheck = collect($decoded['checks'])->firstWhere('check', fn ($v) => str_contains($v, 'SPDX'));
        $this->assertNotNull($spdxCheck, 'SPDX check should be present in output');
        $this->assertTrue($spdxCheck['pass'], 'SPDX check should pass on clean database');
    }

    public function test_totals_are_consistent(): void
    {
        \Illuminate\Support\Facades\Artisan::call('cultural:conformance', ['--format' => 'json']);
        $output  = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertSame(
            count($decoded['checks']),
            $decoded['total'],
            'total should equal number of check entries',
        );
        $this->assertSame(
            $decoded['pass'] + $decoded['fail'],
            $decoded['total'],
            'pass + fail should equal total',
        );
    }
}
