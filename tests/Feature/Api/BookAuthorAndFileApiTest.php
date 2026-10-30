<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for book author and book file management endpoints:
 *   GET  /api/v1/books/{book}/authors
 *   POST /api/v1/books/{book}/authors
 *   GET  /api/v1/books/{book}/files
 *   POST /api/v1/books/{book}/files
 */
final class BookAuthorAndFileApiTest extends TestCase
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

    private function book(User $owner): Book
    {
        return Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Book ' . uniqid(),
            'slug'        => 'book-' . uniqid(),
            'status'      => 'draft',
            'is_free'     => true,
            'price_cents' => 0,
            'currency'    => 'EUR',
        ]);
    }

    // ── BookAuthorController ──────────────────────────────────────────────────

    public function test_authors_index_requires_authentication(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $this->getJson("/api/v1/books/{$book->slug}/authors")->assertStatus(401);
    }

    public function test_owner_can_list_book_authors(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/books/{$book->slug}/authors")
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
    }

    public function test_store_author_requires_authentication(): void
    {
        $owner  = $this->admin();
        $book   = $this->book($owner);
        $author = $this->buyer();

        $this->postJson("/api/v1/books/{$book->slug}/authors", [
            'author_id' => $author->id,
            'share_bps' => 5000,
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_add_author_to_others_book(): void
    {
        $owner  = $this->admin();
        $book   = $this->book($owner);
        $buyer  = $this->buyer();
        $author = $this->buyer();

        $this->actingAs($buyer)
            ->postJson("/api/v1/books/{$book->slug}/authors", [
                'author_id' => $author->id,
                'share_bps' => 5000,
            ])->assertStatus(403);
    }

    public function test_owner_can_add_author(): void
    {
        $owner  = $this->admin();
        $book   = $this->book($owner);
        $author = $this->buyer();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/authors", [
                'author_id' => $author->id,
                'share_bps' => 5000,
            ])->assertStatus(201);

        $this->assertSame($author->id, $response->json('author_id'));
        $this->assertSame(5000, $response->json('share_bps'));
    }

    public function test_store_author_validates_share_bps_max(): void
    {
        $owner  = $this->admin();
        $book   = $this->book($owner);
        $author = $this->buyer();

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/authors", [
                'author_id' => $author->id,
                'share_bps' => 10001, // over max
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['share_bps']);
    }

    public function test_store_author_rejects_duplicate(): void
    {
        $owner  = $this->admin();
        $book   = $this->book($owner);
        $author = $this->buyer();

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/authors", [
                'author_id' => $author->id,
                'share_bps' => 3000,
            ])->assertStatus(201);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/authors", [
                'author_id' => $author->id,
                'share_bps' => 1000,
            ])->assertStatus(422);
    }

    // ── BookFileController ────────────────────────────────────────────────────

    public function test_files_index_requires_authentication(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $this->getJson("/api/v1/books/{$book->slug}/files")->assertStatus(401);
    }

    public function test_buyer_cannot_list_book_files(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->getJson("/api/v1/books/{$book->slug}/files")
            ->assertStatus(403);
    }

    public function test_owner_can_list_book_files(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/books/{$book->slug}/files")
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
    }

    public function test_store_file_validates_type_enum(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/files", [
                'disk'       => 's3',
                'path'       => 'books/test.pdf',
                'filename'   => 'test.pdf',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => str_repeat('a', 64),
                'type'       => 'spreadsheet', // invalid
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_file_validates_sha256_size(): void
    {
        $owner = $this->admin();
        $book  = $this->book($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/files", [
                'disk'       => 's3',
                'path'       => 'books/test.pdf',
                'filename'   => 'test.pdf',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => 'tooshort',
                'type'       => 'full',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['sha256']);
    }
}
