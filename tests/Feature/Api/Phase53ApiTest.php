<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 53: Public artwork search catalog.
 *
 * GET /api/v1/search/artworks  — filterable, sortable, paginated
 * GET /api/v1/search/artworks/{artwork} — public detail
 */
final class Phase53ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeListedArtwork(array $overrides = []): Artwork
    {
        $artist = User::factory()->create();
        DB::table('users')->where('id', $artist->id)->update(['role' => 'artist']);

        return Artwork::create(array_merge([
            'user_id'      => $artist->id,
            'title'        => 'Test Artwork ' . uniqid(),
            'slug'         => 'artwork-' . uniqid(),
            'status'       => 'listed',
            'currency'     => 'BGN',
            'year_created' => 2022,
            'medium'       => 'painting',
        ], $overrides));
    }

    private function attachLot(Artwork $artwork, ?int $buyNowCents = null, ?int $startingBidCents = null): ArtLot
    {
        return ArtLot::create([
            'artwork_id'          => $artwork->id,
            'sale_mode'           => $buyNowCents ? 'sell_now' : 'auction',
            'status'              => 'active',
            'buy_now_price_cents' => $buyNowCents,
            'starting_bid_cents'  => $startingBidCents,
            'currency'            => 'BGN',
        ]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_only_listed_artworks(): void
    {
        $listed = $this->makeListedArtwork(['title' => 'Public Painting']);
        $draft  = $this->makeListedArtwork(['status' => 'draft', 'slug' => 'draft-' . uniqid()]);

        $response = $this->getJson('/api/v1/search/artworks');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($listed->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    public function test_index_filter_by_medium(): void
    {
        $painting   = $this->makeListedArtwork(['medium' => 'painting']);
        $sculpture  = $this->makeListedArtwork(['medium' => 'sculpture']);

        $response = $this->getJson('/api/v1/search/artworks?medium=painting');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($painting->id, $ids);
        $this->assertNotContains($sculpture->id, $ids);
    }

    public function test_index_filter_by_sdg(): void
    {
        $withSdg    = $this->makeListedArtwork();
        $withoutSdg = $this->makeListedArtwork();

        DB::table('artwork_sdg_claims')->insert([
            'artwork_id'  => $withSdg->id,
            'sdg_number'  => 4,
            'rationale'   => 'Promotes quality education themes',
            'status'      => 'approved',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $response = $this->getJson('/api/v1/search/artworks?sdg=4');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($withSdg->id, $ids);
        $this->assertNotContains($withoutSdg->id, $ids);
    }

    public function test_index_filter_by_min_price(): void
    {
        $cheap     = $this->makeListedArtwork();
        $expensive = $this->makeListedArtwork();
        $this->attachLot($cheap, buyNowCents: 5000);
        $this->attachLot($expensive, buyNowCents: 50000);

        $response = $this->getJson('/api/v1/search/artworks?min_price=20000');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($expensive->id, $ids);
        $this->assertNotContains($cheap->id, $ids);
    }

    public function test_index_filter_by_max_price(): void
    {
        $cheap     = $this->makeListedArtwork();
        $expensive = $this->makeListedArtwork();
        $this->attachLot($cheap, buyNowCents: 5000);
        $this->attachLot($expensive, buyNowCents: 50000);

        $response = $this->getJson('/api/v1/search/artworks?max_price=10000');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($cheap->id, $ids);
        $this->assertNotContains($expensive->id, $ids);
    }

    public function test_index_sort_price_asc(): void
    {
        $mid   = $this->makeListedArtwork();
        $high  = $this->makeListedArtwork();
        $low   = $this->makeListedArtwork();
        $this->attachLot($mid, buyNowCents: 30000);
        $this->attachLot($high, buyNowCents: 50000);
        $this->attachLot($low, buyNowCents: 10000);

        $response = $this->getJson('/api/v1/search/artworks?sort=price_asc&min_price=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $lowIdx  = array_search($low->id, $ids);
        $midIdx  = array_search($mid->id, $ids);
        $highIdx = array_search($high->id, $ids);
        $this->assertLessThan($midIdx, $lowIdx);
        $this->assertLessThan($highIdx, $midIdx);
    }

    public function test_index_returns_pagination_meta(): void
    {
        $this->makeListedArtwork();

        $response = $this->getJson('/api/v1/search/artworks');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_index_rejects_invalid_medium(): void
    {
        $this->getJson('/api/v1/search/artworks?medium=invalid_medium')
            ->assertUnprocessable();
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_listed_artwork(): void
    {
        $artwork = $this->makeListedArtwork(['title' => 'My Gallery Piece']);

        $this->getJson("/api/v1/search/artworks/{$artwork->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $artwork->id)
            ->assertJsonPath('data.title', 'My Gallery Piece');
    }

    public function test_show_returns_404_for_unlisted_artwork(): void
    {
        $artwork = $this->makeListedArtwork(['status' => 'draft', 'slug' => 'draft-' . uniqid()]);

        $this->getJson("/api/v1/search/artworks/{$artwork->id}")
            ->assertNotFound();
    }
}
