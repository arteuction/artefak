<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Data\ArtworkData;
use App\Data\BidData;
use App\Data\SellNowOfferData;
use App\Data\UserProfileData;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 73 — API contract tests for Spatie Laravel Data DTOs
 *
 * Verifies that each wired endpoint returns a stable JSON shape
 * matching its Data class contract. These tests catch silent
 * serialization regressions before the frontend layer is built.
 *
 *   Section A — UserProfileData (/api/v1/me)
 *   Section B — ArtworkData (/api/v1/artworks/{artwork})
 *   Section C — SellNowOfferData (/api/v1/art-lots/{artLot}/sell-now-offers)
 *   Section D — Data class unit contracts (fromX factories)
 */
final class Phase73ApiTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Section A — UserProfileData
    // -----------------------------------------------------------------------

    /** @test */
    public function test_me_endpoint_returns_user_profile_data_shape(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'name', 'email', 'role', 'created_at'])
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('name', $user->name)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('role', 'artist');
    }

    /** @test */
    public function test_me_endpoint_does_not_expose_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());
    }

    /** @test */
    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Section B — ArtworkData
    // -----------------------------------------------------------------------

    /** @test */
    public function test_artwork_show_returns_artwork_data_shape(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Sunrise Over Sofia',
            'slug'        => 'sunrise-over-sofia',
            'status'      => 'listed',
            'medium'      => 'painting',
            'year_created' => 2024,
            'is_original' => true,
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'title', 'slug', 'status', 'medium',
                'dimensions', 'year_created', 'description',
                'is_original', 'edition_number', 'edition_total',
                'created_at', 'updated_at', 'artist',
            ])
            ->assertJsonPath('id', $artwork->id)
            ->assertJsonPath('title', 'Sunrise Over Sofia')
            ->assertJsonPath('slug', 'sunrise-over-sofia')
            ->assertJsonPath('status', 'listed')
            ->assertJsonPath('medium', 'painting')
            ->assertJsonPath('year_created', 2024)
            ->assertJsonPath('is_original', true);
    }

    /** @test */
    public function test_artwork_show_includes_artist_summary(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Test Art',
            'slug'    => 'test-art-' . uniqid(),
            'status'  => 'listed',
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}");

        $response->assertStatus(200)
            ->assertJsonPath('artist.id', $artist->id)
            ->assertJsonPath('artist.name', $artist->name);
    }

    /** @test */
    public function test_artwork_show_does_not_expose_user_id_directly(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Private Fields Test',
            'slug'    => 'private-fields-' . uniqid(),
            'status'  => 'listed',
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}");

        $response->assertStatus(200);
        // The raw user_id is not a field in ArtworkData — the artist relation is used
        $this->assertArrayNotHasKey('user_id', $response->json());
    }

    /** @test */
    public function test_artwork_show_draft_requires_owner(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Draft Art',
            'slug'    => 'draft-art-' . uniqid(),
            'status'  => 'draft',
        ]);

        // Public access → 403
        $this->getJson("/api/v1/artworks/{$artwork->slug}")->assertStatus(403);

        // Owner access → 200 with correct shape
        $response = $this->actingAs($artist)->getJson("/api/v1/artworks/{$artwork->slug}");
        $response->assertStatus(200)->assertJsonPath('status', 'draft');
    }

    // -----------------------------------------------------------------------
    // Section C — SellNowOfferData
    // -----------------------------------------------------------------------

    /** @test */
    public function test_sell_now_offer_store_returns_offer_data_shape(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Art for sale',
            'slug'    => 'art-for-sale-' . uniqid(),
            'status'  => 'listed',
        ]);
        $lot = ArtLot::create([
            'artwork_id'      => $artwork->id,
            'consignor_id'    => $artist->id,
            'sale_mode'       => 'sell_now',
            'status'          => 'active',
            'currency'        => 'EUR',
            'ask_price_cents' => 100000,
        ]);

        $response = $this->actingAs($buyer)->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [
            'offered_price_cents' => 90000,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'art_lot_id', 'buyer_id', 'gallery_id',
                'offered_price_cents', 'counter_price_cents', 'agreed_price_cents',
                'currency', 'status', 'notes', 'expires_at', 'created_at',
            ])
            ->assertJsonPath('art_lot_id', $lot->id)
            ->assertJsonPath('buyer_id', $buyer->id)
            ->assertJsonPath('offered_price_cents', 90000)
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('status', 'submitted');
    }

    // -----------------------------------------------------------------------
    // Section D — Data class unit contracts
    // -----------------------------------------------------------------------

    /** @test */
    public function test_user_profile_data_from_user_factory(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $data = UserProfileData::fromUser($user);

        $this->assertSame($user->id, $data->id);
        $this->assertSame($user->name, $data->name);
        $this->assertSame($user->email, $data->email);
        $this->assertSame('buyer', $data->role);
        $this->assertNotNull($data->created_at);
    }

    /** @test */
    public function test_artwork_data_from_artwork_factory(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Unit Test Art',
            'slug'         => 'unit-test-art-' . uniqid(),
            'status'       => 'listed',
            'medium'       => 'sculpture',
            'year_created' => 2023,
            'is_original'  => true,
        ]);
        $artwork->load('artist:id,name');

        $data = ArtworkData::fromArtwork($artwork);

        $this->assertSame($artwork->id, $data->id);
        $this->assertSame('Unit Test Art', $data->title);
        $this->assertSame('listed', $data->status);
        $this->assertSame('sculpture', $data->medium);
        $this->assertSame(2023, $data->year_created);
        $this->assertTrue($data->is_original);
        $this->assertNotNull($data->artist);
        $this->assertSame($artist->id, $data->artist->id);
    }

    /** @test */
    public function test_sell_now_offer_data_serializes_nulls_cleanly(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Null Fields Art',
            'slug'    => 'null-fields-' . uniqid(),
            'status'  => 'listed',
        ]);
        $lot = ArtLot::create([
            'artwork_id'      => $artwork->id,
            'consignor_id'    => $artist->id,
            'sale_mode'       => 'sell_now',
            'status'          => 'active',
            'currency'        => 'EUR',
            'ask_price_cents' => 100000,
        ]);

        $response = $this->actingAs($buyer)->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [
            'offered_price_cents' => 50000,
        ]);

        $response->assertStatus(201);
        $json = $response->json();

        // These nullable fields must be present as null (not absent)
        $this->assertArrayHasKey('counter_price_cents', $json);
        $this->assertArrayHasKey('agreed_price_cents', $json);
        $this->assertArrayHasKey('gallery_id', $json);
        $this->assertArrayHasKey('notes', $json);
        $this->assertArrayHasKey('expires_at', $json);
        $this->assertNull($json['counter_price_cents']);
        $this->assertNull($json['agreed_price_cents']);
    }
}
