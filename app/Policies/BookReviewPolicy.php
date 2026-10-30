<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookReview;
use App\Models\User;

final class BookReviewPolicy
{
    /**
     * Roles allowed to approve or reject any review.
     * curator: content moderation without full admin access.
     */
    private const MODERATORS = ['admin', 'operator', 'curator'];

    public function moderate(User $user): bool
    {
        return in_array($user->role, self::MODERATORS, true);
    }

    /**
     * A reviewer may delete their own pending (not-yet-approved) review.
     * Moderators may delete any review.
     */
    public function delete(User $user, BookReview $review): bool
    {
        if (in_array($user->role, self::MODERATORS, true)) {
            return true;
        }

        // Owner can retract their own review only while it is still pending
        return $review->user_id === $user->id && ! $review->is_approved;
    }

    public function viewPending(User $user): bool
    {
        return in_array($user->role, self::MODERATORS, true);
    }
}
