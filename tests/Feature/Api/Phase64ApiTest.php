<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 64: BookAuthor royalty splits + SplitProfile deprecation.
 */
final class Phase64ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeBook(User $owner): Book
    {
        return Book::create([
            'owner_id' => $owner->id,
            'title'    => 'Test Book ' . uniqid(),
            'slug'     => 'test-book-' . uniqid(),
            'status'   => 'draft',
            'is_free'  => false,
            'currency' => 'BGN',
        ]);
    }

    // ── BookAuthor index ──────────────────────────────────────────────────────

    public function test_book_owner_can_list_book_authors(): void
    {
        $owner  = User::factory()->create();
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);

        BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 10000,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/books/{$book->id}/authors")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($author->id, $response->json('data.0.author_id'));
    }

    // ── BookAuthor store ──────────────────────────────────────────────────────

    public function test_book_owner_can_add_author(): void
    {
        $owner  = User::factory()->create();
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 7500,
            ])
            ->assertCreated();

        $this->assertSame($author->id, $response->json('author_id'));
        $this->assertSame(7500, $response->json('share_bps'));
        $this->assertDatabaseHas('book_authors', [
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 7500,
        ]);
    }

    public function test_non_owner_cannot_add_author(): void
    {
        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $author  = User::factory()->create();
        $book    = $this->makeBook($owner);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 5000,
            ])
            ->assertForbidden();
    }

    public function test_cannot_add_duplicate_author(): void
    {
        $owner  = User::factory()->create();
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);

        BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 5000,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 2500,
            ])
            ->assertUnprocessable();
    }

    public function test_total_split_cannot_exceed_10000_bps(): void
    {
        $owner   = User::factory()->create();
        $author1 = User::factory()->create();
        $author2 = User::factory()->create();
        $book    = $this->makeBook($owner);

        BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author1->id,
            'share_bps' => 8000,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author2->id,
                'share_bps' => 3000,  // 8000 + 3000 = 11000 > 10000
            ])
            ->assertUnprocessable();
    }

    // ── BookAuthor update ─────────────────────────────────────────────────────

    public function test_owner_can_update_author_share_bps(): void
    {
        $owner  = User::factory()->create();
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);
        $ba     = BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 5000,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/authors/{$ba->id}", [
                'share_bps' => 6000,
            ])
            ->assertOk();

        $this->assertSame(6000, $response->json('share_bps'));
    }

    public function test_admin_can_update_any_book_author(): void
    {
        $owner  = User::factory()->create();
        $admin  = User::factory()->create(['role' => 'admin']);
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);
        $ba     = BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 5000,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/authors/{$ba->id}", [
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('sort_order', 2);
    }

    // ── BookAuthor destroy ────────────────────────────────────────────────────

    public function test_owner_can_remove_author(): void
    {
        $owner  = User::factory()->create();
        $author = User::factory()->create();
        $book   = $this->makeBook($owner);
        $ba     = BookAuthor::create([
            'book_id'   => $book->id,
            'author_id' => $author->id,
            'share_bps' => 10000,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}/authors/{$ba->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('book_authors', ['id' => $ba->id]);
    }

    // ── SplitProfile deprecation ──────────────────────────────────────────────

    private function makeSplitProfile(string $key = 'standard', string $status = 'active'): int
    {
        return DB::table('split_profiles')->insertGetId([
            'profile_key'    => $key,
            'version'        => 1,
            'artist_bps'     => 8500,
            'fund_bps'       => 1000,
            'ops_bps'        => 500,
            'status'         => $status,
            'effective_from' => '2026-01-01',
            'created_by'     => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_admin_can_deprecate_active_split_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->makeSplitProfile('to_deprecate', 'active');

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertOk();

        $this->assertSame('deprecated', $response->json('data.status'));
        $this->assertDatabaseHas('split_profiles', ['id' => $id, 'status' => 'deprecated']);
    }

    public function test_cannot_deprecate_superseded_split_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id    = $this->makeSplitProfile('superseded_key', 'superseded');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_deprecate_split_profile(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $id    = $this->makeSplitProfile('buyer_test', 'active');

        $this->actingAs($buyer, 'sanctum')
            ->patchJson("/api/v1/admin/split-profiles/{$id}/deprecate")
            ->assertForbidden();
    }

    public function test_deprecate_returns_404_for_unknown_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/split-profiles/99999999/deprecate')
            ->assertNotFound();
    }
}
