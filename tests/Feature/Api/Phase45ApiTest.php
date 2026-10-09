<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\Book;
use App\Models\BookFile;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 45: Consignment cancel, SDG claim show, Book file management.
 */
final class Phase45ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Consignment Cancel ────────────────────────────────────────────────────

    private function makeConsignment(User $owner, string $status = 'draft'): Consignment
    {
        $artwork = Artwork::create([
            'user_id'   => $owner->id,
            'title'     => 'Art ' . uniqid(),
            'slug'      => 'art-' . uniqid(),
            'status'    => 'draft',
            'is_original' => true,
        ]);

        return Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 1500,
            'status'         => $status,
        ]);
    }

    public function test_owner_can_cancel_draft_consignment(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $consignment = $this->makeConsignment($owner, 'draft');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');
    }

    public function test_terminate_is_idempotent_when_already_terminated(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $consignment = $this->makeConsignment($owner, 'terminated');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertOk()
            ->assertJsonPath('message', 'Already terminated.');
    }

    public function test_non_owner_cannot_terminate_consignment(): void
    {
        $owner  = User::factory()->create(['role' => 'artist']);
        $other  = User::factory()->create(['role' => 'artist']);
        $consignment = $this->makeConsignment($owner, 'draft');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertForbidden();
    }

    public function test_cannot_terminate_completed_consignment(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $consignment = $this->makeConsignment($owner, 'completed');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertStatus(422);
    }

    // ── SDG Claim Show ────────────────────────────────────────────────────────

    public function test_public_can_view_sdg_claim(): void
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'SDG Art',
            'slug'        => 'sdg-art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
        $claim = ArtworkSdgClaim::create([
            'artwork_id'  => $artwork->id,
            'submitted_by'=> $artist->id,
            'sdg_number'  => 4,
            'rationale'   => 'Supports quality education.',
            'status'      => 'approved',
        ]);

        $this->getJson("/api/v1/artworks/{$artwork->id}/sdg-claims/{$claim->id}")
            ->assertOk()
            ->assertJsonPath('data.sdg_number', 4);
    }

    public function test_sdg_claim_show_returns_404_for_wrong_artwork(): void
    {
        $artist   = User::factory()->create(['role' => 'artist']);
        $artwork1 = Artwork::create([
            'user_id' => $artist->id, 'title' => 'A1', 'slug' => 'a1-' . uniqid(), 'status' => 'draft', 'is_original' => true,
        ]);
        $artwork2 = Artwork::create([
            'user_id' => $artist->id, 'title' => 'A2', 'slug' => 'a2-' . uniqid(), 'status' => 'draft', 'is_original' => true,
        ]);
        $claim = ArtworkSdgClaim::create([
            'artwork_id'  => $artwork2->id,
            'submitted_by'=> $artist->id,
            'sdg_number'  => 5,
            'rationale'   => 'Gender equality.',
            'status'      => 'pending',
        ]);

        $this->getJson("/api/v1/artworks/{$artwork1->id}/sdg-claims/{$claim->id}")
            ->assertNotFound();
    }

    // ── Book File Management ──────────────────────────────────────────────────

    private function makeBook(User $owner): Book
    {
        return Book::create([
            'owner_id'   => $owner->id,
            'title'      => 'Book ' . uniqid(),
            'slug'       => 'book-' . uniqid(),
            'status'     => 'draft',
            'is_free'    => false,
            'currency'   => 'BGN',
            'price_cents'=> 990,
        ]);
    }

    public function test_owner_can_register_book_file(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/files", [
                'disk'       => 's3',
                'path'       => 'books/1/full_v1.pdf',
                'filename'   => 'full_v1.pdf',
                'mime'       => 'application/pdf',
                'size_bytes' => 2048000,
                'sha256'     => str_repeat('a', 64),
                'type'       => 'full',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'full')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_book_file_version_auto_increments(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);

        BookFile::create([
            'book_id' => $book->id, 'uploaded_by' => $owner->id,
            'disk' => 's3', 'path' => 'p1.pdf', 'filename' => 'p1.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'sha256' => str_repeat('c', 64), 'type' => 'full', 'version' => 1, 'status' => 'published',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/files", [
                'disk' => 's3', 'path' => 'p2.pdf', 'filename' => 'p2.pdf',
                'mime' => 'application/pdf', 'size_bytes' => 1500, 'sha256' => str_repeat('b', 64), 'type' => 'full',
            ])
            ->assertStatus(201);

        $this->assertEquals(2, $response->json('data.version'));
    }

    public function test_owner_can_mark_file_published(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);
        $file  = BookFile::create([
            'book_id' => $book->id, 'uploaded_by' => $owner->id,
            'disk' => 's3', 'path' => 'p.pdf', 'filename' => 'p.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'sha256' => str_repeat('d', 64), 'type' => 'full', 'version' => 1, 'status' => 'processing',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}/files/{$file->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_cannot_delete_published_file(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = $this->makeBook($owner);
        $file  = BookFile::create([
            'book_id' => $book->id, 'uploaded_by' => $owner->id,
            'disk' => 's3', 'path' => 'p.pdf', 'filename' => 'p.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'sha256' => str_repeat('e', 64), 'type' => 'full', 'version' => 1, 'status' => 'published',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}/files/{$file->id}")
            ->assertStatus(422);
    }

    public function test_non_owner_cannot_access_book_files(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $other = User::factory()->create(['role' => 'buyer']);
        $book  = $this->makeBook($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/books/{$book->id}/files")
            ->assertForbidden();
    }
}
