<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Artwork;
use App\Models\User;

final class ArtworkPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** View a non-published artwork (owner or staff). */
    public function view(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Edit artwork metadata (owner or staff). */
    public function update(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Create artwork — only artists, admin, operator. */
    public function create(User $user): bool
    {
        return in_array($user->role, ['artist', ...self::STAFF], true);
    }

    /** Submit/activate a revision (owner or staff). */
    public function manageRevision(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Request presigned image upload URL (owner or staff). */
    public function uploadImage(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Confirm presigned upload after S3 PUT (owner or staff). */
    public function confirmUpload(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Publish artwork to public catalogue (owner or staff). */
    public function publish(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Create a new lot from this artwork (owner or staff). */
    public function createLot(User $user, Artwork $artwork): bool
    {
        return $artwork->user_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }
}
