<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Domain\Library\ApproveBookReview;
use App\Domain\Library\SubmitBookReview;
use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApproveBookReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;
    private User $reviewer;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moderator = User::factory()->create(['role' => 'admin']);
        $this->reviewer  = User::factory()->create(['role' => 'buyer']);

        $author = User::factory()->create(['role' => 'artist']);

        $this->book = Book::create([
            'owner_id'    => $author->id,
            'title'       => 'Bulgarian Masters',
            'slug'        => 'bulgarian-masters',
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);
    }

    private function approve(): ApproveBookReview
    {
        return new ApproveBookReview();
    }

    private function makeReview(int $rating = 4, ?User $user = null): BookReview
    {
        return BookReview::create([
            'book_id'     => $this->book->id,
            'user_id'     => ($user ?? $this->reviewer)->id,
            'rating'      => $rating,
            'headline'    => 'Wonderful collection',
            'body'        => 'Every piece tells a story.',
            'is_approved' => false,
        ]);
    }

    // ── approve happy path ────────────────────────────────────────────────────

    public function test_approve_marks_review_as_approved(): void
    {
        $review = $this->makeReview();

        $approved = $this->approve()->execute($review, $this->moderator);

        $this->assertTrue($approved->is_approved);
        $this->assertSame($this->moderator->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_approve_refreshes_rating_cache_on_book(): void
    {
        $this->makeReview(5);           // pending, not counted
        $r2 = $this->makeReview(3, User::factory()->create(['role' => 'buyer']));

        $this->approve()->execute($r2, $this->moderator);

        $this->book->refresh();
        $this->assertSame(3.0, $this->book->avg_rating);
        $this->assertSame(1, $this->book->review_count);
    }

    public function test_approve_averages_multiple_reviews(): void
    {
        $users = User::factory()->count(3)->create(['role' => 'buyer']);

        $reviews = [
            $this->makeReview(4, $users[0]),
            $this->makeReview(2, $users[1]),
            $this->makeReview(3, $users[2]),
        ];

        foreach ($reviews as $review) {
            $this->approve()->execute($review, $this->moderator);
        }

        $this->book->refresh();
        $this->assertSame(3.0, $this->book->avg_rating);
        $this->assertSame(3, $this->book->review_count);
    }

    public function test_approving_already_approved_review_is_idempotent(): void
    {
        $review = $this->makeReview(5);
        $this->approve()->execute($review, $this->moderator);

        // second call must not throw and must not double-count
        $this->approve()->execute($review->refresh(), $this->moderator);

        $this->book->refresh();
        $this->assertSame(1, $this->book->review_count);
    }

    // ── reject / soft-delete ─────────────────────────────────────────────────

    public function test_reject_soft_deletes_pending_review(): void
    {
        $review = $this->makeReview();

        $this->approve()->reject($review);

        $this->assertSoftDeleted('book_reviews', ['id' => $review->id]);
    }

    public function test_reject_does_not_alter_cache_when_review_was_pending(): void
    {
        // pre-seed an approved review from a different user
        $other = User::factory()->create(['role' => 'buyer']);
        $approved = $this->makeReview(5, $other);
        $this->approve()->execute($approved, $this->moderator);

        $pending = $this->makeReview(1); // never approved
        $this->approve()->reject($pending);

        $this->book->refresh();
        // cache must reflect only the approved review
        $this->assertSame(5.0, $this->book->avg_rating);
        $this->assertSame(1, $this->book->review_count);
    }

    public function test_reject_approved_review_recalculates_cache(): void
    {
        $u1 = User::factory()->create(['role' => 'buyer']);
        $u2 = User::factory()->create(['role' => 'buyer']);

        $r1 = $this->makeReview(4, $u1);
        $r2 = $this->makeReview(2, $u2);

        $this->approve()->execute($r1, $this->moderator);
        $this->approve()->execute($r2, $this->moderator);

        // avg = (4+2)/2 = 3.0
        $this->book->refresh();
        $this->assertSame(3.0, $this->book->avg_rating);

        // remove the 4-star review
        $this->approve()->reject($r1);

        $this->book->refresh();
        $this->assertSame(2.0, $this->book->avg_rating);
        $this->assertSame(1, $this->book->review_count);
    }

    public function test_cache_resets_to_null_when_all_approved_reviews_are_rejected(): void
    {
        $review = $this->makeReview(5);
        $this->approve()->execute($review, $this->moderator);

        $this->book->refresh();
        $this->assertSame(1, $this->book->review_count);

        $this->approve()->reject($review);

        $this->book->refresh();
        $this->assertNull($this->book->avg_rating);
        $this->assertSame(0, $this->book->review_count);
    }

    // ── integration with SubmitBookReview ────────────────────────────────────

    public function test_full_flow_submit_then_approve(): void
    {
        $submit = new SubmitBookReview();

        $review = $submit->execute(
            book:     $this->book,
            reviewer: $this->reviewer,
            rating:   4,
            headline: 'Must read',
        );

        $this->assertFalse($review->is_approved);

        $approved = $this->approve()->execute($review, $this->moderator);

        $this->assertTrue($approved->is_approved);
        $this->book->refresh();
        $this->assertSame(4.0, $this->book->avg_rating);
    }
}
