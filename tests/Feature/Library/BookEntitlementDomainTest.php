<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Domain\Library\GrantBookEntitlement;
use App\Domain\Library\RevokeBookEntitlement;
use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GrantBookEntitlement and RevokeBookEntitlement domain actions.
 */
final class BookEntitlementDomainTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function book(bool $isFree = false): Book
    {
        $owner = User::factory()->create(['role' => 'artist']);

        return Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Book ' . uniqid(),
            'slug'        => 'book-' . uniqid(),
            'price_cents' => $isFree ? 0 : 1999,
            'currency'    => 'EUR',
            'is_free'     => $isFree,
            'status'      => 'published',
        ]);
    }

    private function purchase(User $buyer, Book $book): BookPurchase
    {
        return BookPurchase::create([
            'buyer_id'         => $buyer->id,
            'book_id'          => $book->id,
            'status'           => 'paid',
            'price_cents'      => 1999,
            'gross_cents'      => 1999,
            'split_base_cents' => 1999,
            'profile_key'      => 'library_80_10_10',
            'profile_version'  => 1,
            'author_bps'       => 8000,
            'fund_bps'         => 1000,
            'ops_bps'          => 1000,
            'currency'         => 'EUR',
            'idempotency_key'  => 'test-' . uniqid(),
        ]);
    }

    // ── GrantBookEntitlement ─────────────────────────────────────────────────

    public function test_grant_for_purchase_creates_paid_entitlement(): void
    {
        $buyer    = $this->buyer();
        $book     = $this->book();
        $purchase = $this->purchase($buyer, $book);

        $entitlement = (new GrantBookEntitlement())->forPurchase($purchase);

        $this->assertSame($buyer->id, $entitlement->user_id);
        $this->assertSame($book->id, $entitlement->book_id);
        $this->assertSame('paid', $entitlement->source);
        $this->assertNull($entitlement->revoked_at);
    }

    public function test_grant_for_purchase_is_idempotent(): void
    {
        $buyer    = $this->buyer();
        $book     = $this->book();
        $purchase = $this->purchase($buyer, $book);

        (new GrantBookEntitlement())->forPurchase($purchase);
        (new GrantBookEntitlement())->forPurchase($purchase);

        $this->assertSame(
            1,
            BookEntitlement::where('user_id', $buyer->id)
                ->where('book_id', $book->id)
                ->where('source', 'paid')
                ->count()
        );
    }

    public function test_grant_for_free_book_creates_free_entitlement(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book(isFree: true);

        $entitlement = (new GrantBookEntitlement())->forFreeBook($buyer, $book);

        $this->assertSame('free', $entitlement->source);
        $this->assertNull($entitlement->book_purchase_id);
        $this->assertNull($entitlement->revoked_at);
    }

    public function test_grant_for_free_book_is_idempotent(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book(isFree: true);

        (new GrantBookEntitlement())->forFreeBook($buyer, $book);
        (new GrantBookEntitlement())->forFreeBook($buyer, $book);

        $this->assertSame(
            1,
            BookEntitlement::where('user_id', $buyer->id)
                ->where('book_id', $book->id)
                ->where('source', 'free')
                ->count()
        );
    }

    public function test_grant_for_admin_creates_admin_entitlement(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book();

        $entitlement = (new GrantBookEntitlement())->forAdmin($buyer, $book);

        $this->assertSame('admin', $entitlement->source);
        $this->assertNull($entitlement->book_purchase_id);
        $this->assertNull($entitlement->revoked_at);
    }

    public function test_grant_for_admin_is_idempotent(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book();

        (new GrantBookEntitlement())->forAdmin($buyer, $book);
        (new GrantBookEntitlement())->forAdmin($buyer, $book);

        $this->assertSame(
            1,
            BookEntitlement::where('user_id', $buyer->id)
                ->where('book_id', $book->id)
                ->where('source', 'admin')
                ->count()
        );
    }

    // ── RevokeBookEntitlement ────────────────────────────────────────────────

    public function test_revoke_for_refund_sets_revoked_at(): void
    {
        $buyer    = $this->buyer();
        $book     = $this->book();
        $purchase = $this->purchase($buyer, $book);
        $grant    = new GrantBookEntitlement();

        $grant->forPurchase($purchase);

        (new RevokeBookEntitlement())->forRefund($purchase);

        $entitlement = BookEntitlement::where('user_id', $buyer->id)
            ->where('book_id', $book->id)
            ->where('source', 'paid')
            ->first();

        $this->assertNotNull($entitlement->revoked_at);
    }

    public function test_revoke_for_refund_is_idempotent(): void
    {
        $buyer    = $this->buyer();
        $book     = $this->book();
        $purchase = $this->purchase($buyer, $book);

        (new GrantBookEntitlement())->forPurchase($purchase);
        (new RevokeBookEntitlement())->forRefund($purchase);

        $firstRevoked = BookEntitlement::where('user_id', $buyer->id)
            ->where('source', 'paid')
            ->value('revoked_at');

        (new RevokeBookEntitlement())->forRefund($purchase);

        $secondRevoked = BookEntitlement::where('user_id', $buyer->id)
            ->where('source', 'paid')
            ->value('revoked_at');

        $this->assertEquals($firstRevoked, $secondRevoked);
    }

    public function test_revoke_for_admin_sets_revoked_at(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book();

        $entitlement = (new GrantBookEntitlement())->forAdmin($buyer, $book);

        (new RevokeBookEntitlement())->forAdmin($entitlement);

        $this->assertNotNull($entitlement->fresh()->revoked_at);
    }

    public function test_revoke_for_admin_is_idempotent(): void
    {
        $buyer = $this->buyer();
        $book  = $this->book();

        $entitlement = (new GrantBookEntitlement())->forAdmin($buyer, $book);

        (new RevokeBookEntitlement())->forAdmin($entitlement);
        $first = $entitlement->fresh()->revoked_at;

        (new RevokeBookEntitlement())->forAdmin($entitlement->fresh());
        $second = $entitlement->fresh()->revoked_at;

        $this->assertEquals($first, $second);
    }

    public function test_revoke_for_refund_when_no_entitlement_is_no_op(): void
    {
        $buyer    = $this->buyer();
        $book     = $this->book();
        $purchase = $this->purchase($buyer, $book);

        // No entitlement was ever granted — should not throw
        (new RevokeBookEntitlement())->forRefund($purchase);

        $this->assertDatabaseMissing('book_entitlements', [
            'user_id' => $buyer->id,
            'book_id' => $book->id,
        ]);
    }
}
