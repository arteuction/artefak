<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 49: S3 presigned book-file upload/download workflow.
 *
 * Uses Storage::fake('s3') so no real AWS calls are made.
 * Presigned URL generation falls back to fake URLs on non-S3 disk drivers.
 */
final class Phase49ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(User $owner): Book
    {
        return Book::create([
            'owner_id'   => $owner->id,
            'title'      => 'Test Book ' . uniqid(),
            'slug'       => 'test-book-' . uniqid(),
            'status'     => 'draft',
            'is_featured' => false,
        ]);
    }

    private function makeProcessingFile(Book $book, User $uploader, string $contents = 'hello'): BookFile
    {
        Storage::fake('s3');
        $sha256 = hash('sha256', $contents);
        $stagingKey = 'books/staging/' . $book->id . '/uuid123/test.pdf';
        Storage::disk('s3')->put($stagingKey, $contents);

        return BookFile::create([
            'book_id'     => $book->id,
            'uploaded_by' => $uploader->id,
            'disk'        => 's3',
            'path'        => $stagingKey,
            'filename'    => 'test.pdf',
            'mime'        => 'application/pdf',
            'size_bytes'  => strlen($contents),
            'sha256'      => $sha256,
            'type'        => 'full',
            'version'     => 1,
            'status'      => 'processing',
        ]);
    }

    private function makePublishedFile(Book $book, User $uploader): BookFile
    {
        Storage::fake('s3');
        $contents = 'published content';
        $publishedKey = 'books/published/' . $book->id . '/1/test.pdf';
        Storage::disk('s3')->put($publishedKey, $contents);

        return BookFile::create([
            'book_id'     => $book->id,
            'uploaded_by' => $uploader->id,
            'disk'        => 's3',
            'path'        => $publishedKey,
            'filename'    => 'test.pdf',
            'mime'        => 'application/pdf',
            'size_bytes'  => strlen($contents),
            'sha256'      => hash('sha256', $contents),
            'type'        => 'full',
            'version'     => 1,
            'status'      => 'published',
        ]);
    }

    private function grantEntitlement(int $userId, int $bookId): void
    {
        DB::table('book_entitlements')->insert([
            'user_id'    => $userId,
            'book_id'    => $bookId,
            'source'     => 'admin',
            'granted_at' => now(),
            'revoked_at' => null,
        ]);
    }

    // ── Upload intent ─────────────────────────────────────────────────────────

    public function test_owner_can_get_upload_intent(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/files/upload-intent", [
                'filename'   => 'chapter1.pdf',
                'type'       => 'full',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => str_repeat('a', 64),
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data', 'upload_url']);

        $this->assertStringContainsString('chapter1.pdf', $response->json('data.filename'));
        $this->assertSame('processing', $response->json('data.status'));
        $this->assertDatabaseHas('book_files', ['book_id' => $book->id, 'status' => 'processing']);
    }

    public function test_non_owner_cannot_get_upload_intent(): void
    {
        Storage::fake('s3');
        $owner  = User::factory()->create(['role' => 'artist']);
        $other  = User::factory()->create(['role' => 'artist']);
        $book   = $this->makeBook($owner);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/files/upload-intent", [
                'filename'   => 'chapter1.pdf',
                'type'       => 'full',
                'mime'       => 'application/pdf',
                'size_bytes' => 1024,
                'sha256'     => str_repeat('b', 64),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_get_upload_intent_for_any_book(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $admin = User::factory()->create(['role' => 'admin']);
        $book  = $this->makeBook($owner);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/files/upload-intent", [
                'filename'   => 'admin-upload.pdf',
                'type'       => 'cover',
                'mime'       => 'image/jpeg',
                'size_bytes' => 200000,
                'sha256'     => str_repeat('c', 64),
            ])
            ->assertStatus(201);
    }

    // ── Complete upload ───────────────────────────────────────────────────────

    public function test_complete_publishes_file_when_sha256_matches(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);
        $file  = $this->makeProcessingFile($book, $owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/book-files/{$file->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // File should now be at published key (not staging)
        $updated = BookFile::find($file->id);
        $this->assertStringContainsString('books/published/', $updated->path);
    }

    public function test_complete_rejects_file_when_sha256_mismatches(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);

        // Put different content in staging than what sha256 claims
        $stagingKey = 'books/staging/' . $book->id . '/uuid456/bad.pdf';
        Storage::disk('s3')->put($stagingKey, 'actual content');

        $file = BookFile::create([
            'book_id'     => $book->id,
            'uploaded_by' => $owner->id,
            'disk'        => 's3',
            'path'        => $stagingKey,
            'filename'    => 'bad.pdf',
            'mime'        => 'application/pdf',
            'size_bytes'  => 100,
            'sha256'      => str_repeat('f', 64), // wrong hash
            'type'        => 'full',
            'version'     => 1,
            'status'      => 'processing',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/book-files/{$file->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_complete_fails_when_object_not_in_staging(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);

        // Record exists but nothing was uploaded to S3
        $file = BookFile::create([
            'book_id'     => $book->id,
            'uploaded_by' => $owner->id,
            'disk'        => 's3',
            'path'        => 'books/staging/' . $book->id . '/missing/file.pdf',
            'filename'    => 'file.pdf',
            'mime'        => 'application/pdf',
            'size_bytes'  => 100,
            'sha256'      => str_repeat('d', 64),
            'type'        => 'full',
            'version'     => 1,
            'status'      => 'processing',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/book-files/{$file->id}/complete")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Uploaded object not found in staging storage. Please retry the upload.']);
    }

    public function test_complete_rejects_already_published_file(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);
        $file  = $this->makePublishedFile($book, $owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/book-files/{$file->id}/complete")
            ->assertStatus(422);
    }

    // ── Download URL ──────────────────────────────────────────────────────────

    public function test_entitled_buyer_gets_download_url(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $book  = $this->makeBook($owner);
        $this->makePublishedFile($book, $owner);
        $this->grantEntitlement($buyer->id, $book->id);

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/my-books/{$book->id}/download-url")
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_in', 'filename']);
    }

    public function test_buyer_without_entitlement_cannot_download(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $book  = $this->makeBook($owner);
        $this->makePublishedFile($book, $owner);

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/my-books/{$book->id}/download-url")
            ->assertForbidden();
    }

    public function test_download_url_fails_when_no_published_full_file(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['role' => 'artist']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $book  = $this->makeBook($owner);
        $this->grantEntitlement($buyer->id, $book->id);
        // No published 'full' file seeded

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/my-books/{$book->id}/download-url")
            ->assertNotFound();
    }
}
