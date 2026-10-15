<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 178 — PublicArtworkController filter/sort coverage.
 *
 * Extends the basic ArtworkSearchTest with tests for:
 *   medium filter, SDG filter, min/max price filter,
 *   sort=price_asc, sort=price_desc, Cyrillic full-text search.
 */
final class ArtworkSearchFiltersTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function listedArtwork(User $owner, array $overrides = []): Artwork
    {
        return Artwork::create(array_merge([
            'user_id'     => $owner->id,
            'title'       => 'Artwork ' . Str::random(4),
            'slug'        => Str::uuid()->toString(),
            'status'      => 'listed',
            'is_original' => true,
        ], $overrides));
    }

    private function activeLot(int $artworkId, int $buyNowCents): void
    {
        DB::table('art_lots')->insert([
            'artwork_id'         => $artworkId,
            'sale_mode'          => 'sell_now',
            'status'             => 'active',
            'buy_now_price_cents'=> $buyNowCents,
            'currency'           => 'EUR',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function sdgClaim(int $artworkId, int $sdg): void
    {
        DB::table('artwork_sdg_claims')->insert([
            'artwork_id'  => $artworkId,
            'sdg_number'  => $sdg,
            'rationale'   => 'Test rationale',
            'status'      => 'approved',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ── Medium filter ─────────────────────────────────────────────────────────

    public function test_medium_filter_returns_only_matching_medium(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, ['title' => 'Painting', 'medium' => 'painting']);
        $this->listedArtwork($artist, ['title' => 'Photo',    'medium' => 'photography']);

        $res    = $this->getJson('/api/v1/search/artworks?medium=painting')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Painting', $titles);
        $this->assertNotContains('Photo',   $titles);
    }

    public function test_medium_filter_digital_excludes_other_mediums(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, ['title' => 'Digital',   'medium' => 'digital']);
        $this->listedArtwork($artist, ['title' => 'Sculpture', 'medium' => 'sculpture']);

        $res    = $this->getJson('/api/v1/search/artworks?medium=digital')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Digital',   $titles);
        $this->assertNotContains('Sculpture', $titles);
    }

    // ── SDG filter ────────────────────────────────────────────────────────────

    public function test_sdg_filter_returns_artworks_with_matching_sdg_claim(): void
    {
        $artist   = $this->artist();
        $sdgWork  = $this->listedArtwork($artist, ['title' => 'SDG Work']);
        $other    = $this->listedArtwork($artist, ['title' => 'Other Work']);

        $this->sdgClaim($sdgWork->id, 7);

        $res    = $this->getJson('/api/v1/search/artworks?sdg=7')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('SDG Work',   $titles);
        $this->assertNotContains('Other Work', $titles);
    }

    public function test_sdg_filter_does_not_include_works_with_different_sdg(): void
    {
        $artist  = $this->artist();
        $sdg7    = $this->listedArtwork($artist, ['title' => 'SDG 7 Work']);
        $sdg13   = $this->listedArtwork($artist, ['title' => 'SDG 13 Work']);

        $this->sdgClaim($sdg7->id,  7);
        $this->sdgClaim($sdg13->id, 13);

        $res    = $this->getJson('/api/v1/search/artworks?sdg=7')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('SDG 7 Work',    $titles);
        $this->assertNotContains('SDG 13 Work', $titles);
    }

    // ── Price filters ─────────────────────────────────────────────────────────

    public function test_min_price_excludes_artworks_priced_below(): void
    {
        $artist = $this->artist();
        $cheap  = $this->listedArtwork($artist, ['title' => 'Cheap']);
        $pricey = $this->listedArtwork($artist, ['title' => 'Pricey']);

        $this->activeLot($cheap->id,  5_000_00);  //   500 EUR
        $this->activeLot($pricey->id, 20_000_00); // 2 000 EUR

        $res    = $this->getJson('/api/v1/search/artworks?min_price=1000000')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Pricey', $titles);
        $this->assertNotContains('Cheap',  $titles);
    }

    public function test_max_price_excludes_artworks_priced_above(): void
    {
        $artist = $this->artist();
        $cheap  = $this->listedArtwork($artist, ['title' => 'Cheap']);
        $pricey = $this->listedArtwork($artist, ['title' => 'Pricey']);

        $this->activeLot($cheap->id,  5_000_00);  //   500 EUR
        $this->activeLot($pricey->id, 20_000_00); // 2 000 EUR

        $res    = $this->getJson('/api/v1/search/artworks?max_price=600000')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Cheap',    $titles);
        $this->assertNotContains('Pricey', $titles);
    }

    public function test_min_and_max_price_together_form_inclusive_range(): void
    {
        $artist  = $this->artist();
        $low     = $this->listedArtwork($artist, ['title' => 'Low']);
        $mid     = $this->listedArtwork($artist, ['title' => 'Mid']);
        $high    = $this->listedArtwork($artist, ['title' => 'High']);

        $this->activeLot($low->id,  50_000);    //    500 EUR
        $this->activeLot($mid->id,  500_000);   //  5 000 EUR
        $this->activeLot($high->id, 5_000_000); // 50 000 EUR

        $res    = $this->getJson('/api/v1/search/artworks?min_price=100000&max_price=1000000')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Mid',   $titles);
        $this->assertNotContains('Low',  $titles);
        $this->assertNotContains('High', $titles);
    }

    // ── Sort ──────────────────────────────────────────────────────────────────

    public function test_sort_price_asc_orders_cheapest_first(): void
    {
        $artist = $this->artist();
        $a      = $this->listedArtwork($artist, ['title' => 'ExpensiveA']);
        $b      = $this->listedArtwork($artist, ['title' => 'CheapB']);

        $this->activeLot($a->id, 100_000_00); // 10 000 EUR
        $this->activeLot($b->id,   1_000_00); //    100 EUR

        $res    = $this->getJson('/api/v1/search/artworks?sort=price_asc')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        // CheapB must appear before ExpensiveA
        $posA = array_search('ExpensiveA', $titles);
        $posB = array_search('CheapB',     $titles);
        $this->assertNotFalse($posB);
        $this->assertNotFalse($posA);
        $this->assertLessThan($posA, $posB);
    }

    public function test_sort_price_desc_orders_most_expensive_first(): void
    {
        $artist = $this->artist();
        $a      = $this->listedArtwork($artist, ['title' => 'ExpensiveA']);
        $b      = $this->listedArtwork($artist, ['title' => 'CheapB']);

        $this->activeLot($a->id, 100_000_00); // 10 000 EUR
        $this->activeLot($b->id,   1_000_00); //    100 EUR

        $res    = $this->getJson('/api/v1/search/artworks?sort=price_desc')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $posA = array_search('ExpensiveA', $titles);
        $posB = array_search('CheapB',     $titles);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    // ── Cyrillic search ───────────────────────────────────────────────────────

    public function test_cyrillic_title_search_returns_matching_artwork(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, ['title' => 'Изгрев над София']);
        $this->listedArtwork($artist, ['title' => 'Нощен пейзаж']);

        $res    = $this->getJson('/api/v1/search/artworks?q=' . urlencode('Изгрев'))->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertContains('Изгрев над София', $titles);
        $this->assertNotContains('Нощен пейзаж',  $titles);
    }

    // ── Invalid filter values ─────────────────────────────────────────────────

    public function test_invalid_medium_value_returns_422(): void
    {
        $this->getJson('/api/v1/search/artworks?medium=invalid-medium')
            ->assertUnprocessable();
    }

    public function test_sdg_out_of_range_returns_422(): void
    {
        $this->getJson('/api/v1/search/artworks?sdg=99')
            ->assertUnprocessable();
    }
}
