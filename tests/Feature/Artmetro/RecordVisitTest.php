<?php

declare(strict_types=1);

namespace Tests\Feature\Artmetro;

use App\Domain\Artmetro\LinkArtLotToArtifact;
use App\Domain\Artmetro\RecordVisit;
use App\Models\ArtLot;
use App\Models\ArtmetroArtifact;
use App\Models\ArtmetroVisit;
use App\Models\Artwork;
use App\Models\Exhibition;
use App\Models\Gallery;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for RecordVisit and LinkArtLotToArtifact domain actions.
 */
final class RecordVisitTest extends TestCase
{
    use RefreshDatabase;

    private ArtmetroArtifact $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia-rv', 'code' => 'SRV']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-srv', 'code' => 'SRV1']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-rv-1', 'ekatte' => '68135', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Venue RV', 'slug' => 'venue-rv', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $exhibition = Exhibition::create([
            'title'     => 'Visit Exhibition',
            'slug'      => 'visit-exhibition-' . uniqid(),
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addDays(7),
        ]);

        $this->artifact = ArtmetroArtifact::create([
            'exhibition_id' => $exhibition->id,
            'title'         => 'Visit Artifact',
            'is_active'     => true,
        ]);
    }

    private function artLot(string $status = 'active'): ArtLot
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Art ' . uniqid(),
            'slug'        => 'art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
        $gallery = Gallery::create([
            'name'   => 'G ' . uniqid(),
            'slug'   => 'g-' . uniqid(),
            'status' => 'active',
            'type'   => 'private',
        ]);

        return ArtLot::create([
            'artwork_id'   => $artwork->id,
            'gallery_id'   => $gallery->id,
            'consignor_id' => $artist->id,
            'sale_mode'    => 'auction',
            'status'       => $status,
        ]);
    }

    // ── RecordVisit ──────────────────────────────────────────────────────────

    public function test_record_visit_creates_visit_row(): void
    {
        $visit = (new RecordVisit())->execute($this->artifact);

        $this->assertInstanceOf(ArtmetroVisit::class, $visit);
        $this->assertSame($this->artifact->id, $visit->artifact_id);
    }

    public function test_record_visit_stores_engagement_flags(): void
    {
        $visit = (new RecordVisit())->execute(
            $this->artifact,
            timeOnPageSeconds: 120,
            videoWatched: true,
            arLaunched: false,
            bidClicked: true,
        );

        $this->assertSame(120, $visit->time_on_page_seconds);
        $this->assertTrue((bool) $visit->video_watched);
        $this->assertFalse((bool) $visit->ar_launched);
        $this->assertTrue((bool) $visit->bid_clicked);
    }

    public function test_record_visit_stores_authenticated_user_id(): void
    {
        $user  = User::factory()->create(['role' => 'buyer']);
        $visit = (new RecordVisit())->execute($this->artifact, userId: $user->id);

        $this->assertSame($user->id, $visit->user_id);
    }

    public function test_record_visit_stores_anonymous_session(): void
    {
        $visit = (new RecordVisit())->execute($this->artifact, sessionId: 'sess-abc-123');

        $this->assertNull($visit->user_id);
        $this->assertSame('sess-abc-123', $visit->session_id);
    }

    public function test_record_visit_truncates_session_id_to_64_chars(): void
    {
        $longSession = str_repeat('x', 100);
        $visit       = (new RecordVisit())->execute($this->artifact, sessionId: $longSession);

        $this->assertSame(64, strlen($visit->session_id));
    }

    public function test_record_visit_clamps_negative_time_to_zero(): void
    {
        $visit = (new RecordVisit())->execute($this->artifact, timeOnPageSeconds: -5);

        $this->assertSame(0, $visit->time_on_page_seconds);
    }

    public function test_record_visit_defaults_all_flags_to_false(): void
    {
        $visit = (new RecordVisit())->execute($this->artifact);

        $this->assertFalse((bool) $visit->video_watched);
        $this->assertFalse((bool) $visit->ar_launched);
        $this->assertFalse((bool) $visit->bid_clicked);
        $this->assertSame(0, $visit->time_on_page_seconds);
    }

    // ── LinkArtLotToArtifact ─────────────────────────────────────────────────

    public function test_link_sets_art_lot_id(): void
    {
        $lot = $this->artLot('active');

        $updated = (new LinkArtLotToArtifact())->execute($this->artifact, $lot);

        $this->assertSame($lot->id, $updated->art_lot_id);
    }

    public function test_link_with_null_clears_art_lot(): void
    {
        $lot = $this->artLot('active');
        (new LinkArtLotToArtifact())->execute($this->artifact, $lot);

        $updated = (new LinkArtLotToArtifact())->execute($this->artifact, null);

        $this->assertNull($updated->art_lot_id);
    }

    public function test_link_throws_for_closed_lot(): void
    {
        $lot = $this->artLot('closed');

        $this->expectException(DomainException::class);

        (new LinkArtLotToArtifact())->execute($this->artifact, $lot);
    }

    public function test_link_allows_open_status(): void
    {
        $lot     = $this->artLot('open');
        $updated = (new LinkArtLotToArtifact())->execute($this->artifact, $lot);

        $this->assertSame($lot->id, $updated->art_lot_id);
    }
}
