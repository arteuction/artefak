<?php

declare(strict_types=1);

namespace Tests\Feature\Artmetro;

use App\Domain\Artmetro\RecordArtifactScan;
use App\Models\ArtmetroArtifact;
use App\Models\Exhibition;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordArtifactScanTest extends TestCase
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
            'title'      => 'Test Exhibition',
            'slug'       => 'test-exhibition',
            'venue_id'   => $venue->id,
            'starts_at'  => now()->subDay(),
            'ends_at'    => now()->addDays(7),
        ]);

        $this->artifact = ArtmetroArtifact::create([
            'exhibition_id' => $exhibition->id,
            'title'         => 'Artifact One',
            'is_active'     => true,
        ]);
    }

    private function action(): RecordArtifactScan
    {
        return new RecordArtifactScan();
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_resolves_artifact_by_qr_token(): void
    {
        $result = $this->action()->execute($this->artifact->qr_token);

        $this->assertSame($this->artifact->id, $result->id);
    }

    public function test_creates_scan_record(): void
    {
        $this->action()->execute(
            qrToken:   $this->artifact->qr_token,
            referrer:  'https://instagram.com/p/abc',
            campaign:  'metro-summer-2026',
            locale:    'bg',
            ipAddress: '1.2.3.4',
            userAgent: 'Mozilla/5.0',
        );

        $this->assertDatabaseHas('artmetro_artifact_scans', [
            'artifact_id' => $this->artifact->id,
            'campaign'    => 'metro-summer-2026',
            'locale'      => 'bg',
            'ip_address'  => '1.2.3.4',
        ]);
    }

    public function test_associates_scan_with_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->action()->execute($this->artifact->qr_token, userId: $user->id);

        $this->assertDatabaseHas('artmetro_artifact_scans', [
            'artifact_id' => $this->artifact->id,
            'user_id'     => $user->id,
        ]);
    }

    public function test_multiple_scans_are_all_recorded(): void
    {
        $this->action()->execute($this->artifact->qr_token);
        $this->action()->execute($this->artifact->qr_token);
        $this->action()->execute($this->artifact->qr_token);

        $this->assertDatabaseCount('artmetro_artifact_scans', 3);
    }

    // ── QR token generation ───────────────────────────────────────

    public function test_qr_token_is_auto_generated_on_create(): void
    {
        $this->assertNotEmpty($this->artifact->qr_token);
        $this->assertSame(1, $this->artifact->qr_version);
    }

    public function test_each_artifact_gets_a_unique_qr_token(): void
    {
        $exhibition = $this->artifact->exhibition;

        $b = ArtmetroArtifact::create(['exhibition_id' => $exhibition->id, 'title' => 'B', 'is_active' => true]);
        $c = ArtmetroArtifact::create(['exhibition_id' => $exhibition->id, 'title' => 'C', 'is_active' => true]);

        $tokens = [$this->artifact->qr_token, $b->qr_token, $c->qr_token];
        $this->assertSame(array_unique($tokens), $tokens);
    }

    // ── Error paths ───────────────────────────────────────────────

    public function test_throws_when_token_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action()->execute('not-a-real-token');
    }

    public function test_throws_when_artifact_is_inactive(): void
    {
        $this->artifact->update(['is_active' => false]);

        $this->expectException(ModelNotFoundException::class);

        $this->action()->execute($this->artifact->qr_token);
    }

    // ── Truncation guard ─────────────────────────────────────────

    public function test_long_referrer_is_truncated_to_500_chars(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('x', 600);

        $this->action()->execute($this->artifact->qr_token, referrer: $longUrl);

        $scan = $this->artifact->scans()->latest('scanned_at')->first();
        $this->assertSame(500, mb_strlen($scan->referrer));
    }
}
