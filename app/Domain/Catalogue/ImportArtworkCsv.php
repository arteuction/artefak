<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 95 — Bulk artwork import from CSV (OpenRefine-compatible format).
 *
 * Expected CSV columns (header row required):
 *   title, medium, description, year_created, is_original, artist_email
 *
 * Optional columns: slug, edition_number, edition_total, provenance
 *
 * The CSV must be reconciled before import: artist_email must resolve to an
 * existing User with role=artist. Rows with unresolvable artists are skipped
 * and returned in the $errors result bag.
 */
final class ImportArtworkCsv
{
    private const REQUIRED = ['title', 'artist_email'];
    private const VALID_MEDIUM = ['painting', 'sculpture', 'photography', 'digital', 'nft', 'mixed', 'other'];

    /** @return array{imported: int, skipped: int, errors: list<string>} */
    public function execute(string $csvPath): array
    {
        if (! file_exists($csvPath)) {
            throw new InvalidArgumentException("CSV file not found: {$csvPath}");
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot open CSV file: {$csvPath}");
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            throw new InvalidArgumentException('CSV file is empty or has no header row.');
        }

        $header = array_map('trim', $header);
        foreach (self::REQUIRED as $col) {
            if (! in_array($col, $header, true)) {
                fclose($handle);
                throw new InvalidArgumentException("CSV missing required column: {$col}");
            }
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $row      = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            $record = array_combine($header, array_pad($data, count($header), ''));

            $error = $this->importRow($record, $row);
            if ($error !== null) {
                $errors[] = $error;
                $skipped++;
            } else {
                $imported++;
            }
        }

        fclose($handle);

        return compact('imported', 'skipped', 'errors');
    }

    /** Returns an error string on failure, null on success. */
    private function importRow(array $record, int $row): ?string
    {
        $title = trim($record['title'] ?? '');
        if ($title === '') {
            return "Row {$row}: title is empty.";
        }

        $artistEmail = trim($record['artist_email'] ?? '');
        $artist      = User::where('email', $artistEmail)->where('role', 'artist')->first();
        if (! $artist) {
            return "Row {$row}: artist not found for email '{$artistEmail}'.";
        }

        $medium = trim($record['medium'] ?? '');
        if ($medium !== '' && ! in_array($medium, self::VALID_MEDIUM, true)) {
            return "Row {$row}: invalid medium '{$medium}'. Must be one of: " . implode(', ', self::VALID_MEDIUM);
        }

        $yearRaw     = trim($record['year_created'] ?? '');
        $yearCreated = $yearRaw !== '' ? (int) $yearRaw : null;
        if ($yearCreated !== null && ($yearCreated < 1000 || $yearCreated > (int) date('Y') + 1)) {
            return "Row {$row}: year_created '{$yearRaw}' is out of range.";
        }

        Artwork::create([
            'user_id'        => $artist->id,
            'title'          => $title,
            'slug'           => trim($record['slug'] ?? '') ?: Str::uuid()->toString(),
            'medium'         => $medium ?: 'other',
            'description'    => trim($record['description'] ?? '') ?: null,
            'provenance'     => trim($record['provenance'] ?? '') ?: null,
            'year_created'   => $yearCreated,
            'is_original'    => filter_var($record['is_original'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'edition_number' => ($n = trim($record['edition_number'] ?? '')) !== '' ? (int) $n : null,
            'edition_total'  => ($t = trim($record['edition_total'] ?? '')) !== '' ? (int) $t : null,
            'status'         => 'draft',
        ]);

        return null;
    }
}
