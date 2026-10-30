<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArtistApplication;
use App\Models\User;

final class ArtistApplicationPolicy
{
    private const STAFF = ['admin', 'operator'];

    public function review(User $user, ArtistApplication $application): bool
    {
        return in_array($user->role, self::STAFF, true);
    }
}
