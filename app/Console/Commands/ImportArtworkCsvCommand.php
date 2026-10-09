<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\ImportArtworkCsv;
use Illuminate\Console\Command;

/**
 * Phase 95 — Artisan command to bulk-import artworks from a reconciled CSV.
 *
 * Usage:
 *   php artisan catalogue:import-csv /path/to/artworks.csv
 *   php artisan catalogue:import-csv /path/to/artworks.csv --dry-run
 */
final class ImportArtworkCsvCommand extends Command
{
    protected $signature = 'catalogue:import-csv
                            {path : Absolute path to the CSV file}
                            {--dry-run : Parse and validate only; do not write to the database}';

    protected $description = 'Bulk-import artworks from an OpenRefine-reconciled CSV file';

    public function handle(ImportArtworkCsv $importer): int
    {
        $path   = $this->argument('path');
        $dryRun = $this->option('dry-run');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info("[dry-run] Validating {$path} — no database writes.");
        }

        try {
            $result = $importer->execute($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($dryRun) {
            // Dry-run: report what would happen without persisting
            $this->info("Would import: {$result['imported']}, would skip: {$result['skipped']}");
        } else {
            $this->info("Imported: {$result['imported']}, skipped: {$result['skipped']}");
        }

        foreach ($result['errors'] as $err) {
            $this->warn($err);
        }

        return $result['skipped'] > 0 ? self::INVALID : self::SUCCESS;
    }
}
