<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\ImportArtworkCsv;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 95 — Cultural catalogue import from CSV (OpenRefine patterns).
 */
final class ImportArtworkCsvTest extends TestCase
{
    use RefreshDatabase;

    private function writeCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'artefak_csv_');
        file_put_contents($path, $content);
        return $path;
    }

    public function test_imports_valid_rows(): void
    {
        $artist = User::factory()->create(['role' => 'artist', 'email' => 'artist@example.com']);

        $csv = $this->writeCsv(
            "title,medium,year_created,is_original,artist_email\n" .
            "\"Blue Study\",painting,2001,true,artist@example.com\n"
        );

        $result = (new ImportArtworkCsv())->execute($csv);
        unlink($csv);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('artworks', ['title' => 'Blue Study', 'user_id' => $artist->id]);
    }

    public function test_skips_row_with_unknown_artist(): void
    {
        $csv = $this->writeCsv(
            "title,artist_email\n" .
            "\"Ghost Artwork\",nobody@example.com\n"
        );

        $result = (new ImportArtworkCsv())->execute($csv);
        unlink($csv);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('nobody@example.com', $result['errors'][0]);
    }

    public function test_skips_row_with_invalid_medium(): void
    {
        User::factory()->create(['role' => 'artist', 'email' => 'a@example.com']);

        $csv = $this->writeCsv(
            "title,medium,artist_email\n" .
            "\"Bad Medium\",oil_paint,a@example.com\n"
        );

        $result = (new ImportArtworkCsv())->execute($csv);
        unlink($csv);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_throws_on_missing_required_column(): void
    {
        $csv = $this->writeCsv("title,medium\nFoo,painting\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('artist_email');

        (new ImportArtworkCsv())->execute($csv);
        unlink($csv);
    }

    public function test_throws_on_nonexistent_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImportArtworkCsv())->execute('/nonexistent/path/file.csv');
    }

    public function test_imported_artworks_have_draft_status(): void
    {
        User::factory()->create(['role' => 'artist', 'email' => 'b@example.com']);

        $csv = $this->writeCsv(
            "title,artist_email\n" .
            "\"Draft Work\",b@example.com\n"
        );

        (new ImportArtworkCsv())->execute($csv);
        unlink($csv);

        $this->assertDatabaseHas('artworks', ['title' => 'Draft Work', 'status' => 'draft']);
    }

    public function test_artisan_command_reports_results(): void
    {
        User::factory()->create(['role' => 'artist', 'email' => 'cmd@example.com']);

        $csv = $this->writeCsv(
            "title,artist_email\n" .
            "\"Command Import\",cmd@example.com\n"
        );

        $this->artisan('catalogue:import-csv', ['path' => $csv])
            ->assertExitCode(0);

        unlink($csv);
        $this->assertDatabaseHas('artworks', ['title' => 'Command Import']);
    }
}
