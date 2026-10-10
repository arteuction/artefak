<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 98 — Security audit runner.
 *
 * Runs composer audit and npm audit (production only) and reports findings.
 * Exits non-zero if any high/critical vulnerabilities are found in
 * production dependencies.
 *
 * Usage:
 *   php artisan security:audit
 *   php artisan security:audit --format=json
 */
final class SecurityAuditCommand extends Command
{
    protected $signature = 'security:audit {--format=text : Output format (text|json)}';
    protected $description = 'Run Composer and NPM security audits on production dependencies';

    public function handle(): int
    {
        $hasVulnerabilities = false;

        // Composer audit
        $this->info('Running Composer security audit...');
        $composerOutput = [];
        $composerExit   = 0;
        exec('composer audit --no-interaction 2>&1', $composerOutput, $composerExit);

        if ($composerExit !== 0) {
            $hasVulnerabilities = true;
            $this->error('Composer audit found vulnerabilities:');
            foreach ($composerOutput as $line) {
                $this->line("  {$line}");
            }
        } else {
            $this->info('Composer audit: no known vulnerabilities found.');
        }

        // npm audit (production only — omits dev tools)
        $this->info('Running NPM audit (production deps only)...');
        $npmOutput = [];
        $npmExit   = 0;
        exec('npm audit --omit=dev --audit-level=high 2>&1', $npmOutput, $npmExit);

        if ($npmExit !== 0) {
            $hasVulnerabilities = true;
            $this->error('NPM audit found production vulnerabilities:');
            foreach ($npmOutput as $line) {
                $this->line("  {$line}");
            }
        } else {
            $this->info('NPM audit: no high/critical production vulnerabilities found.');
        }

        return $hasVulnerabilities ? self::FAILURE : self::SUCCESS;
    }
}
