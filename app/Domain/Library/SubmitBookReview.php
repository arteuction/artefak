<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Models\Book;
use App\Models\BookReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SubmitBookReview
{
    /**
     * @throws ValidationException  if the user has not purchased the book
     *                              or has already submitted a review
     */
    public function execute(
        Book   $book,
        User   $reviewer,
        int    $rating,
        ?string $headline = null,
        ?string $body = null,
    ): BookReview {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }

        $hasPurchased = $book->entitlements()
            ->where('user_id', $reviewer->id)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasPurchased && ! $book->is_free) {
            throw ValidationException::withMessages([
                'book' => 'You must purchase this book before leaving a review.',
            ]);
        }

        $existing = BookReview::withTrashed()
            ->where('book_id', $book->id)
            ->where('user_id', $reviewer->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'review' => 'You have already submitted a review for this book.',
            ]);
        }

        return BookReview::create([
            'book_id'  => $book->id,
            'user_id'  => $reviewer->id,
            'rating'   => $rating,
            'headline' => $headline,
            'body'     => $body,
        ]);
    }
}
