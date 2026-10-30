<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private User $artist;
    private Book $freeBook;
    private Book $paidBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = User::factory()->create(['role' => 'artist']);

        $this->freeBook = Book::create([
            'owner_id'    => $this->artist->id,
            'title'       => 'Free Catalogue',
            'slug'        => 'free-catalogue',
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);

        $this->paidBook = Book::create([
            'owner_id'    => $this->artist->id,
            'title'       => 'Paid Monograph',
            'slug'        => 'paid-monograph',
            'price_cents' => 2000,
            'currency'    => 'EUR',
            'is_free'     => false,
            'status'      => 'published',
        ]);
    }

    private function approve(BookReview $review, User $mod): void
    {
        $review->update([
            'is_approved' => true,
            'approved_by' => $mod->id,
            'approved_at' => now(),
        ]);
    }

    // ── GET /api/v1/books/{book}/reviews ─────────────────────────────────────

    public function test_public_can_list_approved_reviews(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $mod  = User::factory()->create(['role' => 'admin']);

        $review = BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => $user->id,
            'rating'  => 5,
        ]);
        $this->approve($review, $mod);

        // pending review — must not appear
        BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => User::factory()->create(['role' => 'buyer'])->id,
            'rating'  => 3,
        ]);

        $this->getJson("/api/v1/books/{$this->freeBook->id}/reviews")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_reviews_404_for_unpublished_book(): void
    {
        $draft = Book::create([
            'owner_id'    => $this->artist->id,
            'title'       => 'Draft',
            'slug'        => 'draft-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'draft',
        ]);

        $this->getJson("/api/v1/books/{$draft->id}/reviews")->assertNotFound();
    }

    // ── POST /api/v1/books/{book}/reviews ────────────────────────────────────

    public function test_user_can_submit_review_for_free_book(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$this->freeBook->id}/reviews", [
                'rating'   => 4,
                'headline' => 'Wonderful',
            ])
            ->assertCreated()
            ->assertJsonPath('rating', 4)
            ->assertJsonPath('is_approved', false);
    }

    public function test_user_with_entitlement_can_review_paid_book(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        BookEntitlement::create([
            'book_id'    => $this->paidBook->id,
            'user_id'    => $user->id,
            'status'     => 'active',
            'granted_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$this->paidBook->id}/reviews", ['rating' => 5])
            ->assertCreated();
    }

    public function test_user_without_entitlement_cannot_review_paid_book(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$this->paidBook->id}/reviews", ['rating' => 3])
            ->assertUnprocessable();
    }

    public function test_rating_validation(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/books/{$this->freeBook->id}/reviews", ['rating' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_guest_cannot_submit_review(): void
    {
        $this->postJson("/api/v1/books/{$this->freeBook->id}/reviews", ['rating' => 4])
            ->assertUnauthorized();
    }

    // ── GET /api/v1/books/{book}/reviews/pending ─────────────────────────────

    public function test_admin_can_view_pending_queue(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user  = User::factory()->create(['role' => 'buyer']);

        BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => $user->id,
            'rating'  => 3,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/books/{$this->freeBook->id}/reviews/pending")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_guest_cannot_view_pending_queue(): void
    {
        $this->getJson("/api/v1/books/{$this->freeBook->id}/reviews/pending")
            ->assertUnauthorized();
    }

    // ── PATCH /api/v1/books/{book}/reviews/{review}/approve ──────────────────

    public function test_admin_can_approve_review(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $user   = User::factory()->create(['role' => 'buyer']);
        $review = BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => $user->id,
            'rating'  => 5,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$this->freeBook->id}/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('is_approved', true);

        $this->freeBook->refresh();
        $this->assertSame(5.0, $this->freeBook->avg_rating);
    }

    public function test_approve_404_if_review_belongs_to_different_book(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $user   = User::factory()->create(['role' => 'buyer']);
        $review = BookReview::create([
            'book_id' => $this->paidBook->id,
            'user_id' => $user->id,
            'rating'  => 4,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/books/{$this->freeBook->id}/reviews/{$review->id}/approve")
            ->assertNotFound();
    }

    // ── DELETE /api/v1/books/{book}/reviews/{review} ─────────────────────────

    public function test_admin_can_reject_review(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $user   = User::factory()->create(['role' => 'buyer']);
        $review = BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => $user->id,
            'rating'  => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/books/{$this->freeBook->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('rejected', true);

        $this->assertSoftDeleted('book_reviews', ['id' => $review->id]);
    }

    public function test_guest_cannot_reject_review(): void
    {
        $user   = User::factory()->create(['role' => 'buyer']);
        $review = BookReview::create([
            'book_id' => $this->freeBook->id,
            'user_id' => $user->id,
            'rating'  => 1,
        ]);

        $this->deleteJson("/api/v1/books/{$this->freeBook->id}/reviews/{$review->id}")
            ->assertUnauthorized();
    }
}
