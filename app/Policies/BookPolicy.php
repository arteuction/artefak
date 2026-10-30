<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

final class BookPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** Publish or unpublish a book — staff only. */
    public function publish(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** Manage authors or files — book owner or staff. */
    public function manageContent(User $user, Book $book): bool
    {
        return $book->owner_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Grant an entitlement to a user — staff only. */
    public function grantEntitlement(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** Revoke an entitlement — staff only. */
    public function revokeEntitlement(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }
}
