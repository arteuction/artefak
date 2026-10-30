<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;

final class GalleryPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** Create a gallery — admin/operator only. */
    public function create(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** Update gallery metadata — owner (gallery admin) or platform staff. */
    public function update(User $user, Gallery $gallery): bool
    {
        $isGalleryAdmin = $gallery->staff()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        return $isGalleryAdmin || in_array($user->role, self::STAFF, true);
    }

    /** Manage gallery staff roster — gallery admin or platform staff. */
    public function manageStaff(User $user, Gallery $gallery): bool
    {
        $isGalleryAdmin = $gallery->staff()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        return $isGalleryAdmin || in_array($user->role, self::STAFF, true);
    }

    /** Manage exhibitions — gallery staff or platform staff. */
    public function manageExhibition(User $user, Gallery $gallery): bool
    {
        $isStaff = $gallery->staff()->where('user_id', $user->id)->exists();

        return $isStaff || in_array($user->role, self::STAFF, true);
    }
}
