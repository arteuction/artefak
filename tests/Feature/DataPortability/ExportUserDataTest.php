<?php

declare(strict_types=1);

namespace Tests\Feature\DataPortability;

use App\Domain\DataPortability\ExportUserData;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 179 — GDPR Art.20 data-portability export.
 *
 * Verifies ExportUserData returns the correct shape and data
 * while respecting privacy rules (no counterparty PII).
 */
final class ExportUserDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ExportUserData $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user     = User::factory()->create(['role' => 'artist']);
        $this->exporter = app(ExportUserData::class);
    }

    // ── Top-level structure ────────────────────────────────────────────────────

    public function test_export_contains_expected_top_level_keys(): void
    {
        $result = $this->exporter->execute($this->user);

        foreach (['exported_at', 'schema_version', 'subject', 'artworks',
                  'sell_now_offers', 'bids', 'donations', 'ledger_entries',
                  'impact_events', 'watchlist'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    public function test_schema_version_is_1_0(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame('1.0', $result['schema_version']);
    }

    public function test_exported_at_is_iso8601(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $result['exported_at'],
        );
    }

    // ── Subject profile ────────────────────────────────────────────────────────

    public function test_subject_contains_user_profile_fields(): void
    {
        $result  = $this->exporter->execute($this->user);
        $subject = $result['subject'];

        $this->assertSame($this->user->id,    $subject['id']);
        $this->assertSame($this->user->name,  $subject['name']);
        $this->assertSame($this->user->email, $subject['email']);
        $this->assertSame('artist',           $subject['role']);
    }

    public function test_subject_does_not_contain_password_hash(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertArrayNotHasKey('password', $result['subject']);
    }

    // ── Artworks ───────────────────────────────────────────────────────────────

    public function test_artworks_includes_users_own_artworks(): void
    {
        Artwork::create([
            'user_id' => $this->user->id,
            'title'   => 'My Painting',
            'slug'    => 'my-painting',
            'status'  => 'listed',
        ]);

        $result  = $this->exporter->execute($this->user);
        $titles = array_column($result['artworks'], 'title');

        $this->assertContains('My Painting', $titles);
    }

    public function test_artworks_does_not_include_other_users_artworks(): void
    {
        $other = User::factory()->create(['role' => 'artist']);
        Artwork::create([
            'user_id' => $other->id,
            'title'   => 'Not Mine',
            'slug'    => 'not-mine',
            'status'  => 'listed',
        ]);

        $result = $this->exporter->execute($this->user);
        $titles = array_column($result['artworks'], 'title');

        $this->assertNotContains('Not Mine', $titles);
    }

    public function test_artworks_is_empty_when_user_has_none(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['artworks']);
    }

    // ── Watchlist ──────────────────────────────────────────────────────────────

    public function test_watchlist_includes_users_watchlist_items(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->user->id,
            'title'   => 'Watched Work',
            'slug'    => 'watched-work-' . Str::random(4),
            'status'  => 'listed',
        ]);

        DB::table('watchlist')->insert([
            'user_id'      => $this->user->id,
            'subject_type' => 'App\\Models\\Artwork',
            'subject_id'   => $artwork->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $result = $this->exporter->execute($this->user);

        $this->assertNotEmpty($result['watchlist']);
        $subjectIds = array_column($result['watchlist'], 'subject_id');
        $this->assertContains($artwork->id, $subjectIds);
    }

    public function test_watchlist_is_empty_when_user_has_none(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['watchlist']);
    }

    // ── Donations ─────────────────────────────────────────────────────────────

    public function test_donations_is_empty_for_user_with_no_donations(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['donations']);
    }

    // ── Privacy — empty collections ────────────────────────────────────────────

    public function test_bids_is_empty_for_user_with_no_bids(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['bids']);
    }

    public function test_sell_now_offers_is_empty_for_user_with_no_offers(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['sell_now_offers']);
    }

    public function test_impact_events_is_empty_for_user_with_no_artworks(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['impact_events']);
    }

    public function test_ledger_entries_is_empty_for_user_with_no_settlements(): void
    {
        $result = $this->exporter->execute($this->user);

        $this->assertSame([], $result['ledger_entries']);
    }
}
