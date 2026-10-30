<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Models\BookReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApproveBookReview
{
    public function execute(BookReview $review, User $moderator): BookReview
    {
        if ($review->is_approved) {
            return $review;
        }

        DB::transaction(function () use ($review, $moderator): void {
            $review->update([
                'is_approved' => true,
                'approved_by' => $moderator->id,
                'approved_at' => now(),
            ]);

            $this->refreshRatingCache($review->book_id);
        });

        return $review->refresh();
    }

    public function reject(BookReview $review): void
    {
        $wasApproved = $review->is_approved;

        $review->delete();

        if ($wasApproved) {
            $this->refreshRatingCache($review->book_id);
        }
    }

    private function refreshRatingCache(int $bookId): void
    {
        $stats = DB::table('book_reviews')
            ->where('book_id', $bookId)
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->selectRaw('ROUND(AVG(rating), 2) as avg_rating, COUNT(*) as review_count')
            ->first();

        DB::table('books')->where('id', $bookId)->update([
            'avg_rating'   => $stats->avg_rating,
            'review_count' => $stats->review_count,
        ]);
    }
}
