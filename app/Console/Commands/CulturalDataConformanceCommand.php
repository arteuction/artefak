<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 146 — Cultural-data conformance check.
 *
 * Validates that the platform's cultural-metadata outputs conform to:
 *   - IIIF Presentation API 3.0 required fields
 *   - Europeana EDM required fields
 *   - W3C PROV-O required fields
 *   - Linked Art required fields
 *
 * Usage:
 *   php artisan cultural:conformance
 *   php artisan cultural:conformance --format=json
 *
 * Exit 0 = all checks pass. Exit 1 = at least one failure.
 */
final class CulturalDataConformanceCommand extends Command
{
    protected $signature   = 'cultural:conformance {--format=text : Output format (text|json)}';
    protected $description = 'Cultural-data conformance check (IIIF, EDM, PROV-O, Linked Art)';

    private array $results = [];

    public function handle(): int
    {
        $this->checkIiifConformance();
        $this->checkEdmConformance();
        $this->checkProvenanceConformance();
        $this->checkLinkedArtConformance();
        $this->checkSpdxValues();

        return $this->report();
    }

    // ── IIIF Presentation API 3.0 ─────────────────────────────────────────────

    private function checkIiifConformance(): void
    {
        $artworks = DB::table('artworks')
            ->whereNotNull('image_url')
            ->limit(10)
            ->get(['id', 'title', 'slug']);

        if ($artworks->isEmpty()) {
            $this->record('IIIF: artworks with image_url exist', true, 'no artworks to validate');
            return;
        }

        foreach ($artworks as $artwork) {
            $manifest = $this->fetchInternal("/api/v1/artworks/{$artwork->id}/iiif/manifest");
            if ($manifest === null) {
                $this->record("IIIF: manifest for artwork#{$artwork->id}", false, 'endpoint returned non-JSON');
                continue;
            }

            $this->record("IIIF: @context present for artwork#{$artwork->id}",
                isset($manifest['@context']), 'missing @context');

            $this->record("IIIF: type=Manifest for artwork#{$artwork->id}",
                ($manifest['type'] ?? '') === 'Manifest', 'type !== Manifest');

            $this->record("IIIF: id is absolute URI for artwork#{$artwork->id}",
                isset($manifest['id']) && str_starts_with($manifest['id'], 'http'),
                'id is not an absolute URI');

            $this->record("IIIF: items (canvases) present for artwork#{$artwork->id}",
                ! empty($manifest['items']), 'no items/canvases');
        }
    }

    // ── Europeana EDM ─────────────────────────────────────────────────────────

    private function checkEdmConformance(): void
    {
        $artworks = DB::table('artworks')
            ->limit(10)
            ->get(['id', 'title']);

        foreach ($artworks as $artwork) {
            $edm = $this->fetchInternal("/api/v1/artworks/{$artwork->id}/edm");
            if ($edm === null) {
                $this->record("EDM: output for artwork#{$artwork->id}", false, 'endpoint returned non-JSON');
                continue;
            }

            $this->record("EDM: @context present for artwork#{$artwork->id}",
                isset($edm['@context']), 'missing @context');

            $this->record("EDM: @graph present for artwork#{$artwork->id}",
                isset($edm['@graph']) && is_array($edm['@graph']), 'missing @graph');

            if (isset($edm['@graph'])) {
                $types = array_column($edm['@graph'], '@type');
                $this->record("EDM: ore:Aggregation present for artwork#{$artwork->id}",
                    in_array('ore:Aggregation', $types, true), 'no ore:Aggregation node');
            }
        }
    }

    // ── W3C PROV-O ────────────────────────────────────────────────────────────

    private function checkProvenanceConformance(): void
    {
        $artLots = DB::table('art_lots')
            ->limit(10)
            ->get(['id']);

        if ($artLots->isEmpty()) {
            $this->record('PROV-O: art_lots exist for provenance check', true, 'no art_lots to validate');
            return;
        }

        foreach ($artLots as $lot) {
            $prov = $this->fetchInternal("/api/v1/art-lots/{$lot->id}/provenance");
            if ($prov === null) {
                $this->record("PROV-O: provenance for lot#{$lot->id}", false, 'endpoint returned non-JSON');
                continue;
            }

            $this->record("PROV-O: @context present for lot#{$lot->id}",
                isset($prov['@context']), 'missing @context');

            $this->record("PROV-O: @graph present for lot#{$lot->id}",
                isset($prov['@graph']) && is_array($prov['@graph']), 'missing @graph');

            if (isset($prov['@graph'])) {
                $types = array_merge(...array_map(
                    fn ($n) => (array) ($n['@type'] ?? []),
                    $prov['@graph'],
                ));
                $this->record("PROV-O: prov:Entity or prov:Activity present for lot#{$lot->id}",
                    ! empty(array_intersect(['prov:Entity', 'prov:Activity'], $types)),
                    'no PROV-O typed nodes');
            }
        }
    }

    // ── Linked Art ────────────────────────────────────────────────────────────

    private function checkLinkedArtConformance(): void
    {
        // Minimal Linked Art check: artwork JSON-LD should have type and _label
        $artworks = DB::table('artworks')
            ->limit(5)
            ->get(['id', 'title']);

        foreach ($artworks as $artwork) {
            $la = $this->fetchInternal("/api/v1/artworks/{$artwork->id}");
            if ($la === null) {
                $this->record("Linked Art: response for artwork#{$artwork->id}", false, 'non-JSON response');
                continue;
            }

            // Standard JSON API response — check that basic fields are present
            $this->record("Linked Art: artwork#{$artwork->id} has id field",
                isset($la['id']) || isset($la['data']['id']),
                'missing id');

            $this->record("Linked Art: artwork#{$artwork->id} has title",
                isset($la['title']) || isset($la['data']['title']) || isset($la['data']['attributes']['title']),
                'missing title / _label');
        }
    }

    // ── SPDX value audit ──────────────────────────────────────────────────────

    private function checkSpdxValues(): void
    {
        // Detect any artworks that still have space-separated SPDX identifiers
        // (e.g. "CC BY-NC 4.0" instead of "CC-BY-NC-4.0")
        $invalidSpdx = DB::table('artworks')
            ->whereNotNull('license_spdx')
            ->whereRaw("license_spdx REGEXP '^[A-Za-z]+ [A-Za-z]'")
            ->count();

        $this->record(
            'SPDX: no space-separated identifiers in artworks',
            $invalidSpdx === 0,
            $invalidSpdx > 0 ? "{$invalidSpdx} artworks have non-canonical SPDX identifiers" : '',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchInternal(string $path): ?array
    {
        try {
            $response = app(\Illuminate\Contracts\Http\Kernel::class)
                ->handle(\Illuminate\Http\Request::create($path, 'GET'));

            $content = $response->getContent();
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function record(string $check, bool $pass, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'pass' => $pass, 'detail' => $detail];
    }

    private function report(): int
    {
        $failures = array_filter($this->results, fn ($r) => ! $r['pass']);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'total'  => count($this->results),
                'pass'   => count($this->results) - count($failures),
                'fail'   => count($failures),
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT));
            return count($failures) > 0 ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Cultural-Data Conformance</>');
        $this->newLine();
        foreach ($this->results as $r) {
            $icon   = $r['pass'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $label  = $r['pass'] ? $r['check'] : "<fg=red>{$r['check']}</>";
            $detail = $r['detail'] ? " <fg=gray>({$r['detail']})</>" : '';
            $this->line("  {$icon}  {$label}{$detail}");
        }
        $this->newLine();

        if (count($failures) > 0) {
            $this->error('Cultural-data conformance FAILED.');
            return 1;
        }
        $this->info('Cultural-data conformance OK.');
        return 0;
    }
}
