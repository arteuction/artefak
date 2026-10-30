<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for admin auction lifecycle endpoints:
 *   POST   /api/v1/admin/auctions
 *   PATCH  /api/v1/admin/auctions/{auction}
 *   POST   /api/v1/admin/auctions/{auction}/publish
 *   POST   /api/v1/admin/auctions/{auction}/open
 *   POST   /api/v1/admin/auctions/{auction}/close
 *   POST   /api/v1/admin/auctions/{auction}/items
 *   DELETE /api/v1/admin/auctions/{auction}/items/{item}
 */
final class AdminAuctionApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function auction(string $status = 'draft'): Auction
    {
        return Auction::create([
            'title'      => 'Test Auction ' . uniqid(),
            'slug'       => 'test-auction-' . uniqid(),
            'starts_at'  => now()->addDay(),
            'ends_at'    => now()->addDays(3),
            'status'     => $status,
            'currency'   => 'BGN',
        ]);
    }

    /**
     * Insert a pending auction_item row (bypassing FK constraints).
     */
    private function auctionItem(int $auctionId, string $status = 'pending'): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('auction_items')->insertGetId([
            'auction_id'  => $auctionId,
            'art_lot_id'  => 1,
            'lot_number'  => random_int(100, 99999),
            'status'      => $status,
            'fulfillment_status' => 'pending',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        return $id;
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_auction(): void
    {
        $this->postJson('/api/v1/admin/auctions', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_auction(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/admin/auctions', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_auction(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/auctions', [
                'title'     => 'Winter Auction 2026',
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at'   => now()->addDays(3)->toDateTimeString(),
                'currency'  => 'EUR',
            ])
            ->assertStatus(201);

        $this->assertSame('Winter Auction 2026', $response->json('data.title'));
        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/auctions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'starts_at', 'ends_at']);
    }

    public function test_store_rejects_ends_at_before_starts_at(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/auctions', [
                'title'     => 'Bad Dates',
                'starts_at' => now()->addDays(5)->toDateTimeString(),
                'ends_at'   => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_auction(): void
    {
        $auction = $this->auction();

        $this->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'New'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_update_auction(): void
    {
        $auction = $this->auction();

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'New'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_draft_auction(): void
    {
        $auction = $this->auction('draft');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $response->json('data.title'));
    }

    public function test_update_rejects_live_auction(): void
    {
        $auction = $this->auction('live');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'No'])
            ->assertStatus(422);
    }

    public function test_update_rejects_closed_auction(): void
    {
        $auction = $this->auction('closed');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'No'])
            ->assertStatus(422);
    }

    // ── publish ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_publish_auction(): void
    {
        $auction = $this->auction('draft');

        $this->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertStatus(401);
    }

    public function test_buyer_cannot_publish_auction(): void
    {
        $auction = $this->auction('draft');

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertStatus(403);
    }

    public function test_publish_fails_when_auction_has_no_items(): void
    {
        $auction = $this->auction('draft');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertStatus(422);
    }

    public function test_admin_can_publish_draft_auction_with_items(): void
    {
        $auction = $this->auction('draft');
        $this->auctionItem($auction->id, 'pending');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertOk();

        $this->assertSame('published', $response->json('data.status'));
    }

    public function test_publish_rejects_already_published_auction(): void
    {
        $auction = $this->auction('published');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertStatus(422);
    }

    // ── open ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_open_auction(): void
    {
        $auction = $this->auction('published');

        $this->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertStatus(401);
    }

    public function test_open_fails_when_auction_is_not_published(): void
    {
        $auction = $this->auction('draft');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertStatus(422);
    }

    public function test_admin_can_open_published_auction(): void
    {
        $auction = $this->auction('published');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertOk();

        $this->assertSame('live', $response->json('data.status'));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_close_auction(): void
    {
        $auction = $this->auction('live');

        $this->postJson("/api/v1/admin/auctions/{$auction->id}/close")
            ->assertStatus(401);
    }

    public function test_close_fails_when_auction_is_not_live(): void
    {
        $auction = $this->auction('draft');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/close")
            ->assertStatus(422);
    }

    public function test_admin_can_close_live_auction(): void
    {
        $auction = $this->auction('live');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/auctions/{$auction->id}/close")
            ->assertOk();

        $this->assertSame('closed', $response->json('data.status'));
    }
}
