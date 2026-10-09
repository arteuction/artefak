<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\AuctionItem;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 65: MVP API contract — exact response shapes + cross-resource authorization.
 *
 * Two goals:
 *  A) Contract — every field the frontend needs is present and correctly typed.
 *     Locked here so backend changes that break the frontend become visible immediately.
 *  B) Authorization — artist cannot touch another artist's resources; public endpoints
 *     hide private data; private endpoints require authentication.
 */
final class Phase65ApiTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // A. API CONTRACT — response shape assertions
    // ────────────────────────────────────────────────────────────────────────

    // A1. Auth: register + login

    public function test_register_returns_user_and_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name'                  => 'New Buyer',
            'email'                 => 'newbuyer@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $response->assertJsonStructure([
            'user'  => ['id', 'name', 'email'],
            'token',
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('newbuyer@example.com', $response->json('user.email'));
    }

    public function test_login_returns_user_and_token(): void
    {
        User::create([
            'name'     => 'Login User',
            'email'    => 'login@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'login@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $response->assertJsonStructure([
            'user'  => ['id', 'name', 'email'],
            'token',
        ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::create([
            'name'     => 'Wrong Pass',
            'email'    => 'wrongpass@example.com',
            'password' => Hash::make('correct'),
        ]);

        $this->postJson('/api/v1/login', [
            'email'    => 'wrongpass@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable();
    }

    // A2. Public artwork catalogue

    public function test_artwork_index_response_shape(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Listed Work',
            'slug'         => 'listed-work-' . uniqid(),
            'status'       => 'listed',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);

        $response = $this->getJson('/api/v1/artworks')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'title', 'slug', 'status', 'medium', 'user_id'],
            ],
            'total', 'current_page', 'last_page',
        ]);
    }

    public function test_artwork_index_excludes_drafts(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $draft  = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Hidden Draft',
            'slug'    => 'hidden-draft-' . uniqid(),
            'status'  => 'draft',
        ]);
        Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Visible',
            'slug'    => 'visible-' . uniqid(),
            'status'  => 'listed',
        ]);

        $response = $this->getJson('/api/v1/artworks')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($draft->id, $ids);
    }

    // A3. Public artwork detail

    public function test_artwork_show_response_shape(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Showcase Piece',
            'slug'         => 'showcase-' . uniqid(),
            'status'       => 'listed',
            'medium'       => 'sculpture',
            'year_created' => 2022,
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->id}")->assertOk();

        $response->assertJsonStructure([
            'id', 'title', 'slug', 'status', 'medium', 'user_id',
            'art_lots', 'revisions',
        ]);
    }

    // A4. Artist portfolio

    public function test_artist_index_response_shape(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Portfolio Artist',
            'slug'         => 'portfolio-artist-' . uniqid(),
            'status'       => 'approved',
        ]);

        $response = $this->getJson('/api/v1/artists')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'display_name', 'slug'],
            ],
            'meta' => ['total', 'current_page', 'last_page'],
        ]);
    }

    public function test_artist_show_response_shape(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Shape Artist',
            'slug'         => 'shape-artist-' . uniqid(),
            'status'       => 'approved',
        ]);

        $response = $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();

        $response->assertJsonStructure([
            'profile'      => ['id', 'display_name', 'slug'],
            'artworks'     => ['data'],
            'recent_sales',
        ]);
    }

    // A5. Auth endpoints require authentication

    public function test_me_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_returns_user_shape(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk();

        $response->assertJsonStructure(['id', 'name', 'email', 'role']);
        $this->assertSame($user->id, $response->json('id'));
    }

    public function test_my_bids_requires_auth(): void
    {
        $this->getJson('/api/v1/my/bids')->assertUnauthorized();
    }

    public function test_my_won_items_requires_auth(): void
    {
        $this->getJson('/api/v1/my/won-items')->assertUnauthorized();
    }

    // ────────────────────────────────────────────────────────────────────────
    // B. CROSS-RESOURCE AUTHORIZATION
    // ────────────────────────────────────────────────────────────────────────

    // B1. Artwork ownership

    public function test_artist_cannot_patch_another_artists_artwork(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $other = User::factory()->create(['role' => 'artist']);
        $art   = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Owners Artwork',
            'slug'    => 'owners-artwork-' . uniqid(),
            'status'  => 'draft',
        ]);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/artworks/{$art->id}", ['title' => 'Stolen'])
            ->assertForbidden();
    }

    public function test_artist_cannot_add_evidence_to_another_artists_artwork(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $other = User::factory()->create(['role' => 'artist']);
        $art   = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Protected',
            'slug'    => 'protected-art-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/artworks/{$art->id}/evidence", ['type' => 'provenance'])
            ->assertForbidden();
    }

    // B2. ArtLot ownership

    public function test_non_owner_cannot_update_art_lot(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $other = User::factory()->create(['role' => 'buyer']);
        $art   = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Lot Art',
            'slug'    => 'lot-art-' . uniqid(),
            'status'  => 'listed',
        ]);
        $lot = ArtLot::create([
            'artwork_id'   => $art->id,
            'consignor_id' => $owner->id,
            'sale_mode'    => 'auction',
            'status'       => 'draft',
            'currency'     => 'EUR',
        ]);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/art-lots/{$lot->id}", ['reserve_price_cents' => 5000])
            ->assertForbidden();
    }

    // B3. Artwork evidence visibility — public endpoint hides non-public evidence

    public function test_public_artwork_evidence_hides_restricted_evidence(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $art    = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Evidence Test',
            'slug'    => 'evidence-test-' . uniqid(),
            'status'  => 'listed',
        ]);

        // Public evidence — should be visible
        $publicEv = DB::table('artwork_evidence')->insertGetId([
            'artwork_id'          => $art->id,
            'type'                => 'authenticity',
            'verification_status' => 'verified',
            'visibility'          => 'public',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Restricted evidence — must NOT appear in public response
        $restrictedEv = DB::table('artwork_evidence')->insertGetId([
            'artwork_id'          => $art->id,
            'type'                => 'ownership',
            'verification_status' => 'verified',
            'visibility'          => 'restricted',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $response = $this->getJson("/api/v1/artworks/{$art->id}/evidence")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($publicEv, $ids, 'Public verified evidence must appear');
        $this->assertNotContains($restrictedEv, $ids, 'Restricted evidence must not appear publicly');
    }

    // B4. Bidder identity privacy — bid history hides user_id

    public function test_bid_history_does_not_expose_bidder_identity(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $item  = $this->makeClosedItemWithBid($buyer->id);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/auction-items/{$item->id}/bid-history")
            ->assertOk();

        foreach ($response->json('data') as $bid) {
            $this->assertArrayNotHasKey('user_id', $bid, 'Bid history must not expose bidder identity');
        }
    }

    // B5. Admin-only routes block non-admins

    public function test_buyer_cannot_access_admin_users_endpoint(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_buyer_cannot_access_admin_reconciliation_endpoint(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/reconciliation-runs')
            ->assertForbidden();
    }

    public function test_buyer_cannot_trigger_reconciliation(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/admin/reconciliation-runs', [
                'period_start'             => '2026-01-01',
                'period_end'               => '2026-01-31',
                'stripe_received_cents'    => 100000,
                'stripe_transferred_cents' => 50000,
            ])
            ->assertForbidden();
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function makeClosedItemWithBid(int $userId): AuctionItem
    {
        $artist = User::factory()->create();
        $art = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Bid History Art',
            'slug'         => 'bid-history-' . uniqid(),
            'status'       => 'in_auction',
            'year_created' => 2024,
            'medium'       => 'painting',
        ]);
        $lot = ArtLot::create([
            'artwork_id'         => $art->id,
            'sale_mode'          => 'auction',
            'status'             => 'sold',
            'starting_bid_cents' => 1000,
            'currency'           => 'EUR',
        ]);
        $auction = Auction::create([
            'title'     => 'Closed Auction',
            'slug'      => 'closed-' . uniqid(),
            'status'    => 'closed',
            'currency'  => 'EUR',
            'starts_at' => now()->subDays(5),
            'ends_at'   => now()->subDays(1),
        ]);
        $item = AuctionItem::create([
            'auction_id'  => $auction->id,
            'art_lot_id'  => $lot->id,
            'lot_number'  => 1,
            'status'      => 'sold',
        ]);
        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $userId,
            'amount_cents'    => 2000,
            'status'          => 'won',
        ]);

        return $item;
    }
}
