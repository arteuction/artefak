<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArtworkSdgClaim;
use App\Models\User;

final class ArtworkSdgClaimPolicy
{
    public function submit(User $user, ArtworkSdgClaim $claim): bool
    {
        // Artist may only claim their own artworks
        return $user->id === $claim->artwork->user_id;
    }

    public function review(User $user, ArtworkSdgClaim $claim): bool
    {
        return $user->role === 'admin';
    }
}
