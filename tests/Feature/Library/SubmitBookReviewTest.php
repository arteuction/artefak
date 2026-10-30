<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Domain\Library\SubmitBookReview;
use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubmitBookReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $author;
    private Book $paidBook;
    private Book $freeBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'artist']);
        $this->buyer  = User::factory()->create(['role' => 'buyer']);

        $this->paidBook = Book::create([
            'owner_id'    => $this->author->id,
            'title'       => 'Art & Commerce',
            'slug'        => 'art-and-commerce',
            'price_cents' => 1500,
            'currency'    => 'EUR',
            'is_free'     => false,
            'status'      => 'published',
        ]);

        $this->freeBook = Book::create([
            'owner_id'    => $this->author->id,
            'title'       => 'Free Catalogue',
            'slug'        => 'free-catalogue',
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);
    }

    private function action(): SubmitBookReview
    {
        return new SubmitBookReview();
    }

    private function grantEntitlement(User $user, Book $book): void
    {
        BookEntitlement::create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'status'  => 'active',
            'granted_at' => now(),
        ]);
    }

    // ── happy paths ──────────────────────────────────────────────────────────

    public function test_buyer_with_entitlement_can_submit_review(): void
    {
        $this->grantEntitlement($this->buyer, $this->paidBook);

        $review = $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   4,
            headline: 'Insightful read',
            body:     'Covers pricing and market dynamics well.',
        );

        $this->assertInstanceOf(BookReview::class, $review);
        $this->assertSame(4, $review->rating);
        $this->assertSame('Insightful read', $review->headline);
        $this->assertFalse($review->is_approved);
        $this->assertDatabaseHas('book_reviews', [
            'book_id' => $this->paidBook->id,
            'user_id' => $this->buyer->id,
            'rating'  => 4,
        ]);
    }

    public function test_anyone_can_review_a_free_book_without_entitlement(): void
    {
        $stranger = User::factory()->create(['role' => 'buyer']);

        $review = $this->action()->execute(
            book:     $this->freeBook,
            reviewer: $stranger,
            rating:   5,
        );

        $this->assertSame(5, $review->rating);
        $this->assertNull($review->headline);
    }

    public function test_review_body_and_headline_are_optional(): void
    {
        $this->grantEntitlement($this->buyer, $this->paidBook);

        $review = $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   3,
        );

        $this->assertNull($review->body);
        $this->assertNull($review->headline);
    }

    // ── validation failures ───────────────────────────────────────────────────

    public function test_rating_below_1_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->action()->execute(
            book:     $this->freeBook,
            reviewer: $this->buyer,
            rating:   0,
        );
    }

    public function test_rating_above_5_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->action()->execute(
            book:     $this->freeBook,
            reviewer: $this->buyer,
            rating:   6,
        );
    }

    public function test_user_without_entitlement_cannot_review_paid_book(): void
    {
        $this->expectException(ValidationException::class);

        $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   5,
        );
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $this->grantEntitlement($this->buyer, $this->paidBook);

        $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   3,
        );

        $this->expectException(ValidationException::class);

        $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   5,
        );
    }

    public function test_soft_deleted_review_still_blocks_resubmission(): void
    {
        $this->grantEntitlement($this->buyer, $this->paidBook);

        $review = $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   2,
        );

        $review->delete(); // soft-delete

        $this->expectException(ValidationException::class);

        $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   4,
        );
    }

    // ── entitlement edge cases ─────────────────────────────────────────────

    public function test_expired_entitlement_does_not_grant_review_access(): void
    {
        BookEntitlement::create([
            'book_id'    => $this->paidBook->id,
            'user_id'    => $this->buyer->id,
            'granted_at' => now()->subYear(),
            'revoked_at' => now()->subMonth(),
        ]);

        $this->expectException(ValidationException::class);

        $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   4,
        );
    }

    public function test_review_is_pending_moderation_by_default(): void
    {
        $this->grantEntitlement($this->buyer, $this->paidBook);

        $review = $this->action()->execute(
            book:     $this->paidBook,
            reviewer: $this->buyer,
            rating:   5,
        );

        $this->assertFalse($review->is_approved);
        $this->assertNull($review->approved_by);
        $this->assertNull($review->approved_at);
    }
}
