<?php

declare(strict_types=1);

namespace Tests\Feature\LinkedArt;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 94 — Linked Art JSON-LD endpoint.
 */
final class LinkedArtTest extends TestCase
{
    use RefreshDatabase;

    private function listedArtwork(array $override = []): Artwork
    {
        $user = User::factory()->create(['role' => 'artist']);
        return Artwork::create(array_merge([
            'user_id'      => $user->id,
            'title'        => 'Blue Nude Study',
            'slug'         => Str::uuid()->toString(),
            'medium'       => 'painting',
            'description'  => 'A study in blue.',
            'year_created' => 1998,
            'status'       => 'listed',
            'is_original'  => true,
        ], $override));
    }

    public function test_endpoint_returns_json_ld_for_listed_artwork(): void
    {
        $artwork = $this->listedArtwork();

        $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/ld+json')
            ->assertJsonFragment(['type' => 'HumanMadeObject'])
            ->assertJsonFragment(['content' => 'Blue Nude Study']);
    }

    public function test_endpoint_returns_404_for_draft_artwork(): void
    {
        $artwork = $this->listedArtwork(['status' => 'draft']);

        $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertNotFound();
    }

    public function test_json_ld_contains_context(): void
    {
        $artwork = $this->listedArtwork();

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertOk()
            ->json();

        $this->assertStringContainsString('linked.art', $body['@context']);
    }

    public function test_json_ld_includes_medium_as_material(): void
    {
        $artwork = $this->listedArtwork(['medium' => 'sculpture']);

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertOk()
            ->json();

        $materials = collect($body['made_of'] ?? [])->pluck('_label');
        $this->assertTrue($materials->contains('sculpture'));
    }

    public function test_json_ld_includes_creation_year_timespan(): void
    {
        $artwork = $this->listedArtwork(['year_created' => 2005]);

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertOk()
            ->json();

        $this->assertStringContainsString('2005', $body['timespan']['begin_of_the_begin'] ?? '');
    }

    public function test_json_ld_id_is_canonical_url(): void
    {
        $artwork = $this->listedArtwork();

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/linked-art")
            ->assertOk()
            ->json();

        $this->assertStringEndsWith("/api/v1/artworks/{$artwork->id}/linked-art", $body['id']);
    }
}
