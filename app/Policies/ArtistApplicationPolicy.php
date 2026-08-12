<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArtistApplication;
use App\Models\User;

final class ArtistApplicationPolicy
{
    public function review(User $user, ArtistApplication $application): bool
    {
        return $user->role === 'admin';
    }
}
