<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 150 — Partner conformance and release gate.
 *
 * Aggregates all health, conformance, security, and supply-chain checks
 * into a single gate that must pass before any production deployment.
 *
 * Checks run (in order):
 *   1. governance:health          — active policies, no stale escalations
 *   2. security:audit             — OWASP ASVS Level 1/2 checks
 *   3. cultural:conformance       — IIIF, EDM, PROV-O, Linked Art, SPDX
 *   4. supply-chain:audit         — SLSA L1 + OpenSSF Scorecard
 *
 * All sub-commands run with --format=json so their output can be captured
 * and aggregated into a single JSON report.
 *
 * Usage:
 *   php artisan release:gate
 *   php artisan release:gate --format=json
 *   php artisan release:gate --skip=cultural:conformance,supply-chain:audit
 *
 * Exit 0 = release gate passed. Exit 1 = gate blocked.
 */
final class ReleaseGateCommand extends Command
{
    protected $signature = 'release:gate
                            {--format=text : Output format (text|json)}
                            {--skip= : Comma-separated list of check names to skip}';

    protected $description = 'Production release gate — all subsystem health checks must pass';

    private const GATES = [
        'governance:health',
        'security:audit',
        'cultural:conformance',
        'supply-chain:audit',
    ];

    public function handle(): int
    {
        $skip    = array_filter(explode(',', $this->option('skip') ?? ''));
        $skip    = array_map('trim', $skip);
        $gates   = array_filter(self::GATES, fn ($g) => ! in_array($g, $skip, true));
        $results = [];
        $blocked = false;

        foreach ($gates as $gate) {
            $output   = [];
            $exitCode = $this->runGate($gate, $output);
            $pass     = $exitCode === 0;

            if (! $pass) {
                $blocked = true;
            }

            $parsed = $this->parseJson($output);

            $results[$gate] = [
                'pass'     => $pass,
                'exit'     => $exitCode,
                'total'    => $parsed['total']   ?? null,
                'failures' => $parsed['fail']    ?? null,
                'checks'   => $parsed['checks']  ?? [],
            ];
        }

        return $this->report($results, $blocked, $skip);
    }

    private function runGate(string $command, array &$output): int
    {
        exec(
            sprintf('%s artisan %s --format=json --no-interaction 2>&1', PHP_BINARY, $command),
            $output,
            $exitCode,
        );
        return $exitCode;
    }

    private function parseJson(array $lines): array
    {
        $raw = implode('', $lines);
        // Strip ANSI escape codes before decoding
        $raw = preg_replace('/\x1b\[[0-9;]*m/', '', $raw);

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function report(array $results, bool $blocked, array $skip): int
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'release_gate' => $blocked ? 'BLOCKED' : 'PASSED',
                'skipped'      => $skip,
                'gates'        => $results,
            ], JSON_PRETTY_PRINT));
            return $blocked ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Release Gate</>');
        $this->newLine();

        foreach ($results as $gate => $r) {
            $icon  = $r['pass'] ? '<fg=green>✓</>' : '<fg=red>✗ BLOCKED</>';
            $label = $r['pass'] ? $gate : "<fg=red>{$gate}</>";
            $extra = $r['failures'] !== null
                ? " <fg=gray>({$r['total']} checks, {$r['failures']} failures)</>"
                : '';
            $this->line("  {$icon}  {$label}{$extra}");
        }

        if (! empty($skip)) {
            $this->newLine();
            $this->line('  <fg=yellow>Skipped: ' . implode(', ', $skip) . '</>');
        }

        $this->newLine();

        if ($blocked) {
            $this->error('Release gate BLOCKED — fix failures before deploying.');
            return 1;
        }

        $this->info('Release gate PASSED — safe to deploy.');
        return 0;
    }
}
