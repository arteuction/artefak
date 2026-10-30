<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for book review endpoints:
 *   GET   /api/v1/books/{book}/reviews
 *   POST  /api/v1/books/{book}/reviews
 *   GET   /api/v1/books/{book}/reviews/pending
 *   PATCH /api/v1/books/{book}/reviews/{review}/approve
 *   DELETE /api/v1/books/{book}/reviews/{review}
 */
final class BookReviewApiTest extends TestCase
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

    private function draftBook(User $owner): Book
    {
        return Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Draft ' . uniqid(),
            'slug'        => 'draft-' . uniqid(),
            'status'      => 'draft',
            'is_free'     => true,
            'price_cents' => 0,
            'currency'    => 'EUR',
        ]);
    }

    private function review(Book $book, User $reviewer, string $status = 'pending'): BookReview
    {
        return BookReview::create([
            'book_id'     => $book->id,
            'user_id'     => $reviewer->id,
            'rating'      => 4,
            'headline'    => 'Good book.',
            'body'        => 'Very helpful.',
            'status'      => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_published_book_reviews_is_public(): void
    {
        $owner = $this->buyer();
        $book  = $this->publishedBook($owner);

        $this->getJson("/api/v1/books/{$book->id}/reviews")->assertOk();
    }

    public function test_draft_book_reviews_returns_404(): void
    {
        $owner = $this->buyer();
        $book  = $this->draftBook($owner);

        $this->getJson("/api/v1/books/{$book->id}/reviews")->assertStatus(404);
    }

    public function test_index_returns_only_approved_reviews(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);
        $this->review($book, $reviewer, 'pending');
        $this->review($book, $reviewer, 'approved');

        $response = $this->getJson("/api/v1/books/{$book->id}/reviews")->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_submit_review(): void
    {
        $owner = $this->buyer();
        $book  = $this->publishedBook($owner);

        $this->postJson("/api/v1/books/{$book->id}/reviews", ['rating' => 4])
            ->assertStatus(401);
    }

    public function test_buyer_can_submit_review(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);

        $response = $this->actingAs($reviewer)
            ->postJson("/api/v1/books/{$book->id}/reviews", [
                'rating'   => 5,
                'headline' => 'Excellent!',
                'body'     => 'A great read.',
            ])
            ->assertStatus(201);

        $this->assertSame(5, $response->json('rating'));
        $this->assertSame('pending', $response->json('status'));
    }

    public function test_store_validates_rating_range(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);

        $this->actingAs($reviewer)
            ->postJson("/api/v1/books/{$book->id}/reviews", ['rating' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_review_submit_returns_404_for_draft_book(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->draftBook($owner);

        $this->actingAs($reviewer)
            ->postJson("/api/v1/books/{$book->id}/reviews", ['rating' => 4])
            ->assertStatus(404);
    }

    // ── pending ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_pending_reviews(): void
    {
        $owner = $this->buyer();
        $book  = $this->publishedBook($owner);

        $this->getJson("/api/v1/books/{$book->id}/reviews/pending")->assertStatus(401);
    }

    public function test_buyer_cannot_list_pending_reviews(): void
    {
        $owner = $this->buyer();
        $book  = $this->publishedBook($owner);

        $this->actingAs($this->buyer())
            ->getJson("/api/v1/books/{$book->id}/reviews/pending")
            ->assertStatus(403);
    }

    public function test_admin_can_list_pending_reviews(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);
        $this->review($book, $reviewer, 'pending');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/books/{$book->id}/reviews/pending")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_approve_review(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);
        $review   = $this->review($book, $reviewer, 'pending');

        $this->patchJson("/api/v1/books/{$book->id}/reviews/{$review->id}/approve")
            ->assertStatus(401);
    }

    public function test_buyer_cannot_approve_review(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);
        $review   = $this->review($book, $reviewer, 'pending');

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/books/{$book->id}/reviews/{$review->id}/approve")
            ->assertStatus(403);
    }

    public function test_admin_can_approve_pending_review(): void
    {
        $owner    = $this->buyer();
        $reviewer = $this->buyer();
        $book     = $this->publishedBook($owner);
        $review   = $this->review($book, $reviewer, 'pending');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/books/{$book->id}/reviews/{$review->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $response->json('status'));
    }
}
