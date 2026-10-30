<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HTTP guard tests for book file upload endpoints:
 *   POST /api/v1/books/{book}/files/upload-intent
 *   POST /api/v1/book-files/{bookFile}/complete
 *   GET  /api/v1/my-books/{book}/download-url
 *
 * S3/storage calls are not made in these tests — only auth, ownership,
 * and validation guards that fire before any storage interaction.
 */
final class BookFileUploadApiTest extends TestCase
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

    private function publishedBook(User $owner): Book
    {
        return Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Book ' . uniqid(),
            'slug'        => 'book-' . uniqid(),
            'status'      => 'published',
            'is_free'     => true,
            'price_cents' => 0,
            'currency'    => 'EUR',
        ]);
    }

    // ── upload-intent ─────────────────────────────────────────────────────────

    public function test_upload_intent_requires_authentication(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        $this->postJson("/api/v1/books/{$book->slug}/files/upload-intent", [
            'filename'   => 'book.pdf',
            'type'       => 'full',
            'mime'       => 'application/pdf',
            'size_bytes' => 1024,
            'sha256'     => str_repeat('a', 64),
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_upload_book_file(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->postJson("/api/v1/books/{$book->slug}/files/upload-intent", [
                'filename'   => 'book.pdf',
                'type'       => 'full',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => str_repeat('a', 64),
            ])->assertStatus(403);
    }

    public function test_upload_intent_validates_type_enum(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/files/upload-intent", [
                'filename'   => 'book.pdf',
                'type'       => 'illegal_type',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => str_repeat('a', 64),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_upload_intent_validates_sha256_size(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/files/upload-intent", [
                'filename'   => 'book.pdf',
                'type'       => 'full',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => 'tooshort',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['sha256']);
    }

    public function test_upload_intent_validates_size_bytes_max(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/books/{$book->slug}/files/upload-intent", [
                'filename'   => 'book.pdf',
                'type'       => 'full',
                'mime'       => 'application/pdf',
                'size_bytes' => 600_000_000, // over 500 MB cap
                'sha256'     => str_repeat('a', 64),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['size_bytes']);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function test_complete_requires_authentication(): void
    {
        $this->postJson('/api/v1/book-files/1/complete')->assertStatus(401);
    }

    public function test_complete_returns_422_for_non_processing_file(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        Schema::disableForeignKeyConstraints();
        $fileId = DB::table('book_files')->insertGetId([
            'book_id'     => $book->id,
            'uploaded_by' => $owner->id,
            'disk'        => 's3',
            'path'        => 'staging/test.pdf',
            'filename'    => 'test.pdf',
            'mime'        => 'application/pdf',
            'size_bytes'  => 1024,
            'sha256'      => str_repeat('a', 64),
            'type'        => 'full',
            'version'     => 1,
            'status'      => 'published',  // not processing
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $this->actingAs($owner)
            ->postJson("/api/v1/book-files/{$fileId}/complete")
            ->assertStatus(422);
    }

    // ── download-url ──────────────────────────────────────────────────────────

    public function test_download_url_requires_authentication(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);

        $this->getJson("/api/v1/my-books/{$book->slug}/download-url")
            ->assertStatus(401);
    }

    public function test_download_url_returns_403_without_entitlement(): void
    {
        $owner = $this->admin();
        $book  = $this->publishedBook($owner);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->getJson("/api/v1/my-books/{$book->slug}/download-url")
            ->assertStatus(403);
    }
}
