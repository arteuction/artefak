<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Gallery;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 82 — Authorization boundary tests.
 *
 * Verifies that no user can access or mutate another user's resources,
 * and that role restrictions are enforced across the principal user journeys.
 *
 * Boundaries tested:
 *   A. Unauthenticated access to protected endpoints
 *   B. Artist cannot modify another artist's artworks
 *   C. Buyer cannot perform artist actions
 *   D. Artist cannot perform admin actions
 *   E. Buyer cannot perform admin actions
 *   F. Sell Now offer ownership: buyer can only accept own offers
 *   G. Sell Now: artist can only counter offers on own artworks
 *   H. Image upload: only the artwork owner can presign/confirm
 *   I. Evidence: only owner or admin can add evidence
 *   J. Admin endpoints reject non-admin callers
 */
final class AuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $suffix = ''): User
    {
        return User::factory()->create(['role' => 'artist', 'name' => "Artist{$suffix}"]);
    }

    private function buyer(string $suffix = ''): User
    {
        return User::factory()->create(['role' => 'buyer', 'name' => "Buyer{$suffix}"]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function artwork(User $owner, string $status = 'listed'): Artwork
    {
        return Artwork::create([
            'user_id'    => $owner->id,
            'title'      => 'Art ' . uniqid(),
            'slug'       => Str::uuid()->toString(),
            'status'     => $status,
            'is_original' => true,
        ]);
    }

    private function gallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'Gallery ' . uniqid(),
            'slug'   => Str::uuid()->toString(),
            'status' => 'active',
            'type'   => 'private',
        ]);
    }

    private function lot(Artwork $artwork, Gallery $gallery, string $status = 'active'): ArtLot
    {
        return ArtLot::create([
            'artwork_id' => $artwork->id,
            'gallery_id' => $gallery->id,
            'status'     => $status,
            'currency'   => 'EUR',
        ]);
    }

    private function offer(ArtLot $lot, User $buyer, string $status = 'submitted'): SellNowOffer
    {
        return SellNowOffer::create([
            'art_lot_id'          => $lot->id,
            'buyer_id'            => $buyer->id,
            'offered_price_cents' => 10000,
            'currency'            => 'EUR',
            'status'              => $status,
        ]);
    }

    // ── A. Unauthenticated access ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_me_endpoint(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_create_artwork(): void
    {
        $this->postJson('/api/v1/artworks', ['title' => 'x', 'slug' => 'x'])->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_submit_sell_now_offer(): void
    {
        $lot = $this->lot($this->artwork($this->artist()), $this->gallery());
        $this->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [])->assertUnauthorized();
    }

    // ── B. Artist cannot modify another artist's artworks ────────────────────

    public function test_artist_cannot_update_another_artists_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner);

        $this->actingAs($other)
            ->patchJson("/api/v1/artworks/{$art->slug}", ['title' => 'Stolen Title'])
            ->assertForbidden();
    }

    public function test_artist_cannot_add_revision_to_another_artists_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner, 'draft');

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->slug}/revisions", [
                'title' => 'New Title', 'reason' => 'initial',
            ])
            ->assertForbidden();
    }

    public function test_artist_cannot_add_evidence_to_another_artists_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->slug}/evidence", [
                'type' => 'authenticity',
            ])
            ->assertForbidden();
    }

    public function test_artist_cannot_presign_image_for_another_artists_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner, 'draft');

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->slug}/images/presign")
            ->assertForbidden();
    }

    public function test_artist_cannot_confirm_image_for_another_artists_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner, 'draft');

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->slug}/images/confirm")
            ->assertForbidden();
    }

    // ── C. Buyer cannot perform artist actions ────────────────────────────────

    public function test_buyer_cannot_create_artwork(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->postJson('/api/v1/artworks', [
                'title' => 'I Am A Buyer', 'slug' => 'buyer-art', 'is_original' => true,
            ])
            ->assertForbidden();
    }

    public function test_buyer_cannot_counter_a_sell_now_offer(): void
    {
        $artist = $this->artist();
        $buyer1 = $this->buyer('1');
        $buyer2 = $this->buyer('2'); // not the artist

        $gallery = $this->gallery();
        $art     = $this->artwork($artist);
        $lot     = $this->lot($art, $gallery);
        $o       = $this->offer($lot, $buyer1);

        $this->actingAs($buyer2)
            ->postJson("/api/v1/sell-now-offers/{$o->id}/counter", ['counter_price_cents' => 12000])
            ->assertForbidden();
    }

    // ── D. Artist cannot perform admin actions ────────────────────────────────

    public function test_artist_cannot_open_an_auction(): void
    {
        $artist = $this->artist();
        $auction = Auction::create([
            'title'      => 'A',
            'slug'       => 'a-' . uniqid(),
            'status'     => 'published',
            'currency'   => 'EUR',
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addDays(3),
        ]);

        $this->actingAs($artist)
            ->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertForbidden();
    }

    public function test_artist_cannot_access_admin_user_list(): void
    {
        $artist = $this->artist();
        $this->actingAs($artist)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    // ── E. Buyer cannot perform admin actions ─────────────────────────────────

    public function test_buyer_cannot_access_admin_user_list(): void
    {
        $buyer = $this->buyer();
        $this->actingAs($buyer)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_buyer_cannot_open_an_auction(): void
    {
        $buyer = $this->buyer();
        $auction = Auction::create([
            'title'      => 'B',
            'slug'       => 'b-' . uniqid(),
            'status'     => 'published',
            'currency'   => 'EUR',
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addDays(3),
        ]);

        $this->actingAs($buyer)
            ->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertForbidden();
    }

    // ── F. Sell Now: buyer can only accept own offers ─────────────────────────

    public function test_buyer_cannot_accept_another_buyers_offer(): void
    {
        $artist  = $this->artist();
        $buyer1  = $this->buyer('1');
        $buyer2  = $this->buyer('2');
        $gallery = $this->gallery();
        $art     = $this->artwork($artist);
        $lot     = $this->lot($art, $gallery);
        $o       = $this->offer($lot, $buyer1);

        $this->actingAs($buyer2)
            ->postJson("/api/v1/sell-now-offers/{$o->id}/accept")
            ->assertForbidden();
    }

    public function test_buyer_cannot_reject_another_buyers_offer(): void
    {
        $artist  = $this->artist();
        $buyer1  = $this->buyer('1');
        $buyer2  = $this->buyer('2');
        $gallery = $this->gallery();
        $art     = $this->artwork($artist);
        $lot     = $this->lot($art, $gallery);
        $o       = $this->offer($lot, $buyer1);

        $this->actingAs($buyer2)
            ->postJson("/api/v1/sell-now-offers/{$o->id}/reject")
            ->assertForbidden();
    }

    // ── G. Sell Now: artist can only counter offers on own artworks ───────────

    public function test_artist_cannot_counter_offer_on_another_artists_lot(): void
    {
        $artistA = $this->artist('A');
        $artistB = $this->artist('B');
        $buyer   = $this->buyer();
        $gallery = $this->gallery();
        $art     = $this->artwork($artistA); // owned by A
        $lot     = $this->lot($art, $gallery);
        $o       = $this->offer($lot, $buyer);

        // Artist B tries to counter — should be forbidden
        $this->actingAs($artistB)
            ->postJson("/api/v1/sell-now-offers/{$o->id}/counter", ['counter_price_cents' => 12000])
            ->assertForbidden();
    }

    // ── H. Public artwork list hides drafts ───────────────────────────────────

    public function test_public_list_does_not_expose_draft_artworks(): void
    {
        $artist = $this->artist();
        $this->artwork($artist, 'draft');
        $this->artwork($artist, 'listed');

        $response = $this->getJson('/api/v1/artworks')->assertOk();
        $slugs = collect($response->json('data'))->pluck('status');

        $this->assertNotContains('draft', $slugs);
        $this->assertContains('listed', $slugs);
    }

    public function test_unauthenticated_cannot_view_draft_artwork_directly(): void
    {
        $artist = $this->artist();
        $art    = $this->artwork($artist, 'draft');

        $this->getJson("/api/v1/artworks/{$art->slug}")->assertForbidden();
    }

    public function test_other_artist_cannot_view_draft_artwork(): void
    {
        $owner = $this->artist('A');
        $other = $this->artist('B');
        $art   = $this->artwork($owner, 'draft');

        $this->actingAs($other)->getJson("/api/v1/artworks/{$art->slug}")->assertForbidden();
    }

    public function test_owner_can_view_own_draft_artwork(): void
    {
        $owner = $this->artist();
        $art   = $this->artwork($owner, 'draft');

        $this->actingAs($owner)->getJson("/api/v1/artworks/{$art->slug}")->assertOk();
    }

    public function test_admin_can_view_any_draft_artwork(): void
    {
        $admin = $this->admin();
        $art   = $this->artwork($this->artist(), 'draft');

        $this->actingAs($admin)->getJson("/api/v1/artworks/{$art->slug}")->assertOk();
    }
}
