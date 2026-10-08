<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 41: BookAuthor management, User admin management.
 */
final class Phase41ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(): array
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $book  = Book::create([
            'owner_id' => $owner->id,
            'title'    => 'Book ' . uniqid(),
            'slug'     => 'book-' . uniqid(),
            'status'   => 'draft',
            'is_free'  => true,
            'currency' => 'BGN',
            'language' => 'bg',
        ]);
        return [$owner, $book];
    }

    // ── BookAuthor ───────────────────────────────────────────────────────────

    public function test_owner_can_add_book_author(): void
    {
        [$owner, $book] = $this->makeBook();
        $author = User::factory()->create(['role' => 'artist']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 5000,
            ])
            ->assertCreated()
            ->assertJsonFragment(['share_bps' => 5000]);
    }

    public function test_duplicate_author_returns_422(): void
    {
        [$owner, $book] = $this->makeBook();
        $author = User::factory()->create(['role' => 'artist']);
        BookAuthor::create(['book_id' => $book->id, 'author_id' => $author->id, 'share_bps' => 3000, 'sort_order' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 2000,
            ])
            ->assertUnprocessable();
    }

    public function test_overfull_split_returns_422(): void
    {
        [$owner, $book] = $this->makeBook();
        $a1 = User::factory()->create(['role' => 'artist']);
        $a2 = User::factory()->create(['role' => 'artist']);
        BookAuthor::create(['book_id' => $book->id, 'author_id' => $a1->id, 'share_bps' => 8000, 'sort_order' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $a2->id,
                'share_bps' => 3000, // would exceed 10000
            ])
            ->assertUnprocessable();
    }

    public function test_non_owner_cannot_add_book_author(): void
    {
        [, $book] = $this->makeBook();
        $intruder = User::factory()->create(['role' => 'artist']);
        $author   = User::factory()->create(['role' => 'artist']);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/authors", [
                'author_id' => $author->id,
                'share_bps' => 5000,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_remove_book_author(): void
    {
        [$owner, $book] = $this->makeBook();
        $author = User::factory()->create(['role' => 'artist']);
        $ba = BookAuthor::create(['book_id' => $book->id, 'author_id' => $author->id, 'share_bps' => 5000, 'sort_order' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}/authors/{$ba->id}")
            ->assertNoContent();
    }

    // ── User admin ────────────────────────────────────────────────────────────

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'buyer']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_change_user_role(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'artist'])
            ->assertOk()
            ->assertJsonPath('data.role', 'artist');
    }
}
