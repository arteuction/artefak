<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookPurchase;
use App\Models\User;

/**
 * Grants access to a book for a user.
 *
 * Idempotent: if an active entitlement already exists for (user, book, source),
 * returns it without creating a duplicate (unique constraint on those three columns).
 *
 * Free books bypass the purchase — source='free', book_purchase_id=null.
 */
final class GrantBookEntitlement
{
    public function forPurchase(BookPurchase $purchase): BookEntitlement
    {
        return BookEntitlement::firstOrCreate(
            [
                'user_id' => $purchase->buyer_id,
                'book_id' => $purchase->book_id,
                'source'  => 'paid',
            ],
            [
                'book_purchase_id' => $purchase->id,
                'file_version'     => null,
                'granted_at'       => now(),
                'revoked_at'       => null,
            ],
        );
    }

    public function forFreeBook(User $user, Book $book): BookEntitlement
    {
        return BookEntitlement::firstOrCreate(
            [
                'user_id' => $user->id,
                'book_id' => $book->id,
                'source'  => 'free',
            ],
            [
                'book_purchase_id' => null,
                'file_version'     => null,
                'granted_at'       => now(),
                'revoked_at'       => null,
            ],
        );
    }

    public function forAdmin(User $user, Book $book): BookEntitlement
    {
        return BookEntitlement::firstOrCreate(
            [
                'user_id' => $user->id,
                'book_id' => $book->id,
                'source'  => 'admin',
            ],
            [
                'book_purchase_id' => null,
                'file_version'     => null,
                'granted_at'       => now(),
                'revoked_at'       => null,
            ],
        );
    }
}
