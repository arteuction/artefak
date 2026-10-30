<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookCategory;
use App\Models\User;

final class BookCategoryPolicy
{
    private const MODERATORS = ['admin', 'operator'];

    public function create(User $user): bool
    {
        return in_array($user->role, self::MODERATORS, true);
    }

    public function update(User $user, BookCategory $category): bool
    {
        return in_array($user->role, self::MODERATORS, true);
    }

    public function delete(User $user, BookCategory $category): bool
    {
        return in_array($user->role, self::MODERATORS, true);
    }
}
