<?php

declare(strict_types=1);

namespace Tests\Feature\Artmetro;

use App\Models\ArtmetroArtifact;
use App\Models\Exhibition;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactApiTest extends TestCase
{
    use RefreshDatabase;

    private ArtmetroArtifact $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $exhibition = Exhibition::create([
            'title'     => 'Test Exhibition',
            'slug'      => 'test-exhibition',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addDays(7),
        ]);

        $this->artifact = ArtmetroArtifact::create([
            'exhibition_id' => $exhibition->id,
            'title'         => 'Test Artifact',
            'is_active'     => true,
        ]);
    }

    // ── GET /api/artifacts/{qrToken} ─────────────────────────────

    public function test_show_returns_artifact_info(): void
    {
        $this->getJson("/api/artifacts/{$this->artifact->qr_token}")
             ->assertOk()
             ->assertJsonPath('artifact.title', 'Test Artifact')
             ->assertJsonPath('artifact.id', $this->artifact->id);
    }

    public function test_show_does_not_record_a_scan(): void
    {
        $this->getJson("/api/artifacts/{$this->artifact->qr_token}")->assertOk();

        $this->assertDatabaseCount('artmetro_artifact_scans', 0);
    }

    public function test_show_returns_404_for_unknown_token(): void
    {
        $this->getJson('/api/artifacts/no-such-token')->assertNotFound();
    }

    public function test_show_returns_404_for_inactive_artifact(): void
    {
        $this->artifact->update(['is_active' => false]);

        $this->getJson("/api/artifacts/{$this->artifact->qr_token}")->assertNotFound();
    }

    // ── POST /api/artifacts/{qrToken}/scans ──────────────────────

    public function test_record_scan_creates_a_scan_and_returns_201(): void
    {
        $this->postJson("/api/artifacts/{$this->artifact->qr_token}/scans", [
            'campaign' => 'metro-test',
        ])->assertCreated()
          ->assertJsonPath('artifact.id', $this->artifact->id);

        $this->assertDatabaseCount('artmetro_artifact_scans', 1);
        $this->assertDatabaseHas('artmetro_artifact_scans', ['campaign' => 'metro-test']);
    }

    public function test_record_scan_returns_404_for_unknown_token(): void
    {
        $this->postJson('/api/artifacts/no-such-token/scans')->assertNotFound();
    }

    public function test_record_scan_returns_404_for_inactive_artifact(): void
    {
        $this->artifact->update(['is_active' => false]);

        $this->postJson("/api/artifacts/{$this->artifact->qr_token}/scans")->assertNotFound();
    }

    public function test_multiple_scan_posts_all_recorded(): void
    {
        $this->postJson("/api/artifacts/{$this->artifact->qr_token}/scans")->assertCreated();
        $this->postJson("/api/artifacts/{$this->artifact->qr_token}/scans")->assertCreated();

        $this->assertDatabaseCount('artmetro_artifact_scans', 2);
    }
}
