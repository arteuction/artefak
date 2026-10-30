<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin book management endpoints:
 *   POST  /api/v1/admin/books
 *   PATCH /api/v1/admin/books/{book}
 */
final class AdminBookApiTest extends TestCase
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

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function book(User $owner): Book
    {
        return Book::create([
            'owner_id'  => $owner->id,
            'title'     => 'Book ' . uniqid(),
            'slug'      => 'book-' . uniqid(),
            'status'    => 'draft',
            'is_free'   => false,
            'currency'  => 'EUR',
            'price_cents' => 999,
        ]);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_book(): void
    {
        $this->postJson('/api/v1/admin/books', [])->assertStatus(401);
    }

    public function test_buyer_cannot_create_book(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/admin/books', [])
            ->assertStatus(403);
    }

    public function test_admin_can_create_book_for_any_user(): void
    {
        $owner = $this->artist();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/books', [
                'owner_id'    => $owner->id,
                'title'       => 'Admin Created Book',
                'price_cents' => 1500,
                'is_free'     => false,
            ])
            ->assertStatus(201);

        $this->assertSame('Admin Created Book', $response->json('data.title'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame($owner->id, $response->json('data.owner_id'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/books', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id', 'title']);
    }

    public function test_store_validates_owner_exists(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/books', [
                'owner_id' => 99999999,
                'title'    => 'Orphan Book',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);
    }

    public function test_store_validates_publication_year_range(): void
    {
        $owner = $this->artist();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/books', [
                'owner_id'         => $owner->id,
                'title'            => 'Old Book',
                'publication_year' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['publication_year']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_book(): void
    {
        $book = $this->book($this->artist());

        $this->patchJson("/api/v1/admin/books/{$book->id}", [])->assertStatus(401);
    }

    public function test_buyer_cannot_update_book(): void
    {
        $book = $this->book($this->artist());

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/admin/books/{$book->id}", ['title' => 'New'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_any_book(): void
    {
        $book = $this->book($this->artist());

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/books/{$book->id}", [
                'title'       => 'Admin Updated Title',
                'price_cents' => 2500,
            ])
            ->assertOk();

        $this->assertSame('Admin Updated Title', $response->json('data.title'));
        $this->assertSame(2500, $response->json('data.price_cents'));
    }

    public function test_update_returns_404_for_unknown_book(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/v1/admin/books/99999999', ['title' => 'Ghost'])
            ->assertStatus(404);
    }
}
