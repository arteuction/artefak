<?php

declare(strict_types=1);

namespace Tests\Feature\E2e;

use Tests\TestCase;

/**
 * Phase 92 — Playwright E2E scaffolding verification.
 *
 * These PHPUnit tests verify that the E2E test infrastructure is wired
 * correctly. The actual Playwright specs run separately via `npm run test:e2e`
 * and require a live server.
 */
final class PlaywrightScaffoldingTest extends TestCase
{
    public function test_playwright_config_exists(): void
    {
        $this->assertFileExists(base_path('playwright.config.ts'));
    }

    public function test_e2e_smoke_spec_exists(): void
    {
        $this->assertFileExists(base_path('tests/e2e/smoke.spec.ts'));
    }

    public function test_e2e_auth_spec_exists(): void
    {
        $this->assertFileExists(base_path('tests/e2e/auth.spec.ts'));
    }

    public function test_playwright_is_in_dev_dependencies(): void
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('@playwright/test', $pkg['devDependencies'] ?? []);
    }

    public function test_e2e_npm_scripts_are_defined(): void
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('test:e2e', $pkg['scripts'] ?? []);
    }
}
