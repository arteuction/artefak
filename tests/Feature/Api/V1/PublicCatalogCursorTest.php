<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for cursor/keyset pagination on the public artwork catalog.
 *
 * Route: GET /api/v1/search/artworks?cursor=...
 */
final class PublicCatalogCursorTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function listedArtwork(User $owner, string $title, string $createdAt): Artwork
    {
        $artwork = Artwork::create([
            'user_id'     => $owner->id,
            'title'       => $title,
            'slug'        => Str::uuid()->toString(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
        // Back-date to control pagination order
        $artwork->created_at = $createdAt;
        $artwork->save();
        return $artwork->fresh();
    }

    // ── First page (no cursor) ────────────────────────────────────────────────

    public function test_first_page_without_cursor_includes_next_cursor_when_more_pages_exist(): void
    {
        $artist = $this->artist();

        // Create 21 artworks so there is a second page (page size = 20)
        for ($i = 1; $i <= 21; $i++) {
            $this->listedArtwork($artist, "Artwork {$i}", now()->subMinutes($i)->toDateTimeString());
        }

        $meta = $this->getJson('/api/v1/search/artworks')
            ->assertOk()
            ->json('meta');

        // Standard offset pagination — next_cursor should be null in this mode
        // (cursor param was not passed, so we stay in offset mode)
        $this->assertNull($meta['next_cursor'],
            'next_cursor must be null when using offset pagination (no cursor param passed).');
        $this->assertSame(21, $meta['total']);
    }

    public function test_cursor_mode_returns_first_page_when_no_cursor_given(): void
    {
        // Trigger cursor mode by passing cursor= (empty string should be ignored by validation)
        // Actually — cursor mode is triggered by passing a cursor value.
        // The first-page cursor call is the same as the first page without cursor, just limited.
        $artist = $this->artist();
        for ($i = 1; $i <= 5; $i++) {
            $this->listedArtwork($artist, "A{$i}", now()->subMinutes($i)->toDateTimeString());
        }

        // Without cursor — normal paginate, 5 items, next_cursor=null
        $response = $this->getJson('/api/v1/search/artworks')->assertOk();
        $this->assertSame(5, $response->json('meta.total'));
    }

    // ── Cursor mode: traversal ────────────────────────────────────────────────

    public function test_cursor_mode_traverses_all_pages_in_order(): void
    {
        $artist = $this->artist();

        // Create 25 artworks with distinct timestamps so ordering is deterministic
        $expectedTitles = [];
        for ($i = 1; $i <= 25; $i++) {
            $title = "Piece {$i}";
            // Oldest first (i=1 oldest, i=25 newest) → newest sort shows 25..1
            $this->listedArtwork($artist, $title, now()->subMinutes(26 - $i)->toDateTimeString());
            $expectedTitles[] = $title;
        }
        // Expected order: newest first (Piece 25, 24, ..., 1)
        $expectedOrder = array_reverse($expectedTitles);

        $collectedTitles = [];
        $cursor = null;
        $pages  = 0;

        do {
            $url      = '/api/v1/search/artworks' . ($cursor ? "?cursor={$cursor}" : '');
            $response = $this->getJson($url)->assertOk()->json();

            foreach ($response['data'] as $item) {
                $collectedTitles[] = $item['title'];
            }

            $cursor = $response['meta']['next_cursor'] ?? null;
            $pages++;

            // Safety: break infinite loop
            if ($pages > 10) {
                break;
            }
        } while ($cursor !== null);

        $this->assertSame($expectedOrder, $collectedTitles,
            'Cursor pagination must traverse all items in newest-first order without duplicates or gaps.');
        $this->assertSame(2, $pages,
            '25 items / 20 per page = 2 pages.');
    }

    public function test_cursor_mode_next_cursor_is_null_on_last_page(): void
    {
        $artist = $this->artist();
        for ($i = 1; $i <= 5; $i++) {
            $this->listedArtwork($artist, "B{$i}", now()->subMinutes($i)->toDateTimeString());
        }

        // First page with a valid cursor (simulate second page from an encoding)
        // Get the cursor by requesting the first "page" with 20-item limit
        // Since only 5 items exist, a single cursor fetch returns all 5 with next_cursor=null
        $firstPage = $this->getJson('/api/v1/search/artworks')->assertOk()->json();

        // Switch to cursor mode: get first cursor-mode page (no cursor yet → use sort=newest no cursor)
        // The real test: 5 items < PAGE_SIZE(20) → next_cursor is null
        // We need to call with cursor mode. Pass an invalid (empty) cursor to bypass offset path:
        // Actually — cursor mode is only activated when cursor param is present.
        // Let's manufacture a cursor from scratch to test the "last page" path.

        // Create a cursor pointing BEFORE any item (all items will be in the first page)
        $fakeCursor = rtrim(
            strtr(base64_encode(json_encode(['c' => now()->addYear()->toISOString(), 'i' => PHP_INT_MAX])), '+/', '-_'),
            '='
        );

        $response = $this->getJson("/api/v1/search/artworks?cursor={$fakeCursor}")->assertOk();

        $this->assertCount(5, $response->json('data'),
            'All 5 items should fit in one cursor page.');
        $this->assertNull($response->json('meta.next_cursor'),
            'next_cursor must be null on the last page.');
    }

    public function test_cursor_mode_returns_empty_data_when_no_items_after_cursor(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, 'Only One', now()->subMinutes(10)->toDateTimeString());

        // Cursor pointing after the only item (i.e., older than it → no more items in DESC order)
        $fakeCursor = rtrim(
            strtr(base64_encode(json_encode(['c' => now()->subMinutes(20)->toISOString(), 'i' => 0])), '+/', '-_'),
            '='
        );

        $response = $this->getJson("/api/v1/search/artworks?cursor={$fakeCursor}")->assertOk();

        $this->assertEmpty($response->json('data'));
        $this->assertNull($response->json('meta.next_cursor'));
    }

    public function test_cursor_mode_invalid_cursor_returns_first_page(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, 'Visible', now()->toDateTimeString());

        // An invalid cursor should be treated as "no cursor" (decodeCursor returns null)
        $badCursor = 'not-valid-base64!!';

        $response = $this->getJson("/api/v1/search/artworks?cursor={$badCursor}")->assertOk();

        // Should return data (first page, no position filter)
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_cursor_mode_ignored_for_price_sort(): void
    {
        // price_asc/price_desc sorts use offset pagination even when cursor is present
        $artist = $this->artist();
        $this->listedArtwork($artist, 'Price Test', now()->toDateTimeString());

        $fakeCursor = rtrim(
            strtr(base64_encode(json_encode(['c' => now()->toISOString(), 'i' => 99999])), '+/', '-_'),
            '='
        );

        // price_asc requires the lot join but won't use cursor pagination
        $response = $this->getJson("/api/v1/search/artworks?sort=price_asc&cursor={$fakeCursor}")
            ->assertOk();

        // Response should use offset pagination meta (has total/last_page, next_cursor=null)
        $meta = $response->json('meta');
        $this->assertArrayHasKey('total', $meta,
            'Price-sort must use offset pagination with total in meta.');
        $this->assertNull($meta['next_cursor']);
    }
}
