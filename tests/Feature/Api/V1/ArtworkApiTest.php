<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtworkApiTest extends TestCase
{
    use RefreshDatabase;

    private User $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = User::factory()->create(['role' => 'artist']);
    }

    public function test_index_returns_non_draft_artworks(): void
    {
        Artwork::create(['user_id' => $this->artist->id, 'title' => 'Listed', 'slug' => 'listed-1', 'status' => 'listed']);
        Artwork::create(['user_id' => $this->artist->id, 'title' => 'Draft',  'slug' => 'draft-1',  'status' => 'draft']);

        $this->getJson('/api/v1/artworks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Listed'])
            ->assertJsonMissing(['title' => 'Draft']);
    }

    public function test_index_filters_by_status(): void
    {
        Artwork::create(['user_id' => $this->artist->id, 'title' => 'In Auction', 'slug' => 'in-auction-1', 'status' => 'in_auction']);
        Artwork::create(['user_id' => $this->artist->id, 'title' => 'Listed',    'slug' => 'listed-2',     'status' => 'listed']);

        $this->getJson('/api/v1/artworks?status=in_auction')
            ->assertOk()
            ->assertJsonFragment(['title' => 'In Auction'])
            ->assertJsonMissing(['title' => 'Listed']);
    }

    public function test_show_returns_artwork(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'My Piece',
            'slug'    => 'my-piece',
            'status'  => 'listed',
        ]);

        $this->getJson("/api/v1/artworks/{$artwork->slug}")
            ->assertOk()
            ->assertJsonFragment(['title' => 'My Piece']);
    }

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/v1/artworks', ['title' => 'X', 'slug' => 'x'])
            ->assertUnauthorized();
    }

    public function test_store_creates_artwork_as_draft(): void
    {
        $this->actingAs($this->artist)
            ->postJson('/api/v1/artworks', [
                'title'  => 'New Work',
                'slug'   => 'new-work-' . uniqid(),
                'medium' => 'painting',
            ])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'New Work', 'status' => 'draft']);
    }

    public function test_store_validates_unique_slug(): void
    {
        Artwork::create(['user_id' => $this->artist->id, 'title' => 'Existing', 'slug' => 'taken-slug', 'status' => 'listed']);

        $this->actingAs($this->artist)
            ->postJson('/api/v1/artworks', ['title' => 'Another', 'slug' => 'taken-slug'])
            ->assertUnprocessable();
    }
}
