<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for artwork collection endpoints:
 *   GET    /api/v1/collections
 *   POST   /api/v1/collections
 *   GET    /api/v1/collections/{collection}
 *   PATCH  /api/v1/collections/{collection}
 *   DELETE /api/v1/collections/{collection}
 *   POST   /api/v1/collections/{collection}/artworks
 *   DELETE /api/v1/collections/{collection}/artworks/{artwork}
 */
final class CollectionApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function collection(User $owner, string $visibility = 'private'): Collection
    {
        return Collection::create([
            'owner_id'   => $owner->id,
            'title'      => 'My Collection ' . uniqid(),
            'slug'       => 'coll-' . uniqid(),
            'visibility' => $visibility,
        ]);
    }

    private function artwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Art ' . uniqid(),
            'slug'        => 'art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    // ── index (auth required) ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_collections(): void
    {
        $this->getJson('/api/v1/collections')->assertStatus(401);
    }

    public function test_buyer_sees_only_own_collections(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();

        $this->collection($buyer1);
        $this->collection($buyer2);

        $response = $this->actingAs($buyer1)
            ->getJson('/api/v1/collections')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_index_returns_empty_for_buyer_with_no_collections(): void
    {
        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/collections')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_collection(): void
    {
        $this->postJson('/api/v1/collections', ['title' => 'Test'])->assertStatus(401);
    }

    public function test_buyer_can_create_collection(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->postJson('/api/v1/collections', [
                'title'      => 'Favorites',
                'visibility' => 'private',
            ])
            ->assertStatus(201);

        $this->assertSame('Favorites', $response->json('title'));
        $this->assertSame($buyer->id, $response->json('owner_id'));
    }

    public function test_store_validates_required_title(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/collections', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_store_validates_visibility_enum(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/collections', [
                'title'      => 'Bad Visibility',
                'visibility' => 'secret',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visibility']);
    }

    public function test_store_defaults_visibility_to_private(): void
    {
        $response = $this->actingAs($this->buyer())
            ->postJson('/api/v1/collections', ['title' => 'No Visibility Set'])
            ->assertStatus(201);

        $this->assertSame('private', $response->json('visibility'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_owner_can_show_private_collection(): void
    {
        $buyer = $this->buyer();
        $coll  = $this->collection($buyer, 'private');

        $this->actingAs($buyer)
            ->getJson("/api/v1/collections/{$coll->id}")
            ->assertOk();
    }

    public function test_unauthenticated_cannot_show_private_collection(): void
    {
        $buyer = $this->buyer();
        $coll  = $this->collection($buyer, 'private');

        $this->getJson("/api/v1/collections/{$coll->id}")->assertStatus(403);
    }

    public function test_other_user_cannot_show_private_collection(): void
    {
        $owner    = $this->buyer();
        $intruder = $this->buyer();
        $coll     = $this->collection($owner, 'private');

        $this->actingAs($intruder)
            ->getJson("/api/v1/collections/{$coll->id}")
            ->assertStatus(403);
    }

    public function test_public_collection_visible_without_auth(): void
    {
        $buyer = $this->buyer();
        $coll  = $this->collection($buyer, 'public');

        $this->getJson("/api/v1/collections/{$coll->id}")->assertOk();
    }

    // ── addArtwork ────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_add_artwork_to_collection(): void
    {
        $buyer  = $this->buyer();
        $coll   = $this->collection($buyer);
        $art    = $this->artwork($this->artist());

        $this->postJson("/api/v1/collections/{$coll->id}/artworks", ['artwork_id' => $art->id])
            ->assertStatus(401);
    }

    public function test_owner_can_add_artwork_to_collection(): void
    {
        $buyer = $this->buyer();
        $coll  = $this->collection($buyer);
        $art   = $this->artwork($this->artist());

        $this->actingAs($buyer)
            ->postJson("/api/v1/collections/{$coll->id}/artworks", ['artwork_id' => $art->id])
            ->assertOk();

        $this->assertDatabaseHas('collection_artworks', [
            'collection_id' => $coll->id,
            'artwork_id'    => $art->id,
        ]);
    }

    public function test_non_owner_cannot_add_artwork_to_collection(): void
    {
        $owner    = $this->buyer();
        $intruder = $this->buyer();
        $coll     = $this->collection($owner);
        $art      = $this->artwork($this->artist());

        $this->actingAs($intruder)
            ->postJson("/api/v1/collections/{$coll->id}/artworks", ['artwork_id' => $art->id])
            ->assertStatus(403);
    }
}
