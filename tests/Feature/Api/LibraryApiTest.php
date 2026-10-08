<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private function makePublishedBook(bool $isFree = false): Book
    {
        $owner = User::factory()->create(['role' => 'artist']);
        return Book::create([
            'owner_id'          => $owner->id,
            'title'             => 'Test Book ' . uniqid(),
            'slug'              => 'test-book-' . uniqid(),
            'price_cents'       => $isFree ? 0 : 1500,
            'currency'          => 'EUR',
            'is_free'           => $isFree,
            'status'            => 'published',
            'profile_key'       => 'book_default',
        ]);
    }

    public function test_public_can_list_published_books(): void
    {
        $this->makePublishedBook();

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_public_can_view_book_detail(): void
    {
        $book = $this->makePublishedBook();

        $this->getJson("/api/v1/books/{$book->id}")
            ->assertOk()
            ->assertJsonFragment(['slug' => $book->slug]);
    }

    public function test_free_book_grants_entitlement_immediately(): void
    {
        $book = $this->makePublishedBook(isFree: true);
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/purchase")
            ->assertOk()
            ->assertJsonStructure(['entitlement']);
    }

    public function test_paid_book_creates_pending_purchase(): void
    {
        $book = $this->makePublishedBook(isFree: false);
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/purchase")
            ->assertStatus(201)
            ->assertJsonPath('purchase.status', 'pending');
    }

    public function test_purchase_is_idempotent_for_pending(): void
    {
        $book = $this->makePublishedBook(isFree: false);
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/books/{$book->id}/purchase");
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/purchase")
            ->assertOk(); // second call returns existing pending
    }

    public function test_my_books_returns_active_entitlements(): void
    {
        $book = $this->makePublishedBook(isFree: true);
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/books/{$book->id}/purchase");

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/my-books')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_grant_book_directly(): void
    {
        $book   = $this->makePublishedBook();
        $admin  = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'artist']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/books/{$book->id}/grant", ['user_id' => $target->id])
            ->assertStatus(201)
            ->assertJsonFragment(['source' => 'admin']);
    }
}
