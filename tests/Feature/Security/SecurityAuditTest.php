<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Phase 98 — OSV / dependency vulnerability scanning scaffolding.
 *
 * These tests verify that the security audit infrastructure is wired
 * correctly. The actual vulnerability scans run in CI via the
 * .github/workflows/security.yml workflow.
 */
final class SecurityAuditTest extends TestCase
{
    public function test_github_security_workflow_exists(): void
    {
        $this->assertFileExists(base_path('.github/workflows/security.yml'));
    }

    public function test_security_workflow_has_osv_scanner_job(): void
    {
        $content = file_get_contents(base_path('.github/workflows/security.yml'));
        $this->assertStringContainsString('osv-scanner', $content);
        $this->assertStringContainsString('composer.lock', $content);
        $this->assertStringContainsString('package-lock.json', $content);
    }

    public function test_security_workflow_audits_production_npm_deps_only(): void
    {
        $content = file_get_contents(base_path('.github/workflows/security.yml'));
        // Production npm audit must omit dev to avoid false positives from build tools
        $this->assertStringContainsString('--omit=dev', $content);
    }

    public function test_security_artisan_command_is_registered(): void
    {
        $this->artisan('security:audit --help')->assertExitCode(0);
    }

    public function test_composer_lock_is_present_for_osv_scanning(): void
    {
        $this->assertFileExists(base_path('composer.lock'));
    }

    public function test_package_lock_is_present_for_osv_scanning(): void
    {
        $this->assertFileExists(base_path('package-lock.json'));
    }

    public function test_npm_audit_prod_script_defined(): void
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('audit:prod', $pkg['scripts'] ?? []);
        $this->assertStringContainsString('--omit=dev', $pkg['scripts']['audit:prod']);
    }
}
