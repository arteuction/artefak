<?php

declare(strict_types=1);

namespace App\Domain\Gallery;

use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use InvalidArgumentException;

/**
 * Add a user to a gallery with a specific role.
 *
 * Rules:
 *   - Role must be one of GalleryStaff::ROLES.
 *   - The inviting user must hold an active 'owner' role at the gallery.
 *   - A user cannot hold the same role twice (unique constraint guards this,
 *     but we check first for a friendly error message).
 *   - A revoked staff row may be re-activated by calling this action again.
 */
final class AddGalleryStaff
{
    public function execute(
        Gallery $gallery,
        User    $user,
        string  $role,
        User    $invitedBy,
    ): GalleryStaff {
        if (! in_array($role, GalleryStaff::ROLES, true)) {
            throw new InvalidArgumentException(
                "Unknown role '{$role}'. Valid roles: " . implode(', ', GalleryStaff::ROLES)
            );
        }

        if (! $gallery->hasRole($invitedBy, 'owner')) {
            throw new \DomainException(
                "Only gallery owners can add staff (user #{$invitedBy->id} is not an owner)."
            );
        }

        // Re-activate a previously revoked row rather than creating a duplicate.
        $existing = GalleryStaff::where('gallery_id', $gallery->id)
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'active') {
                return $existing; // idempotent
            }

            $existing->update([
                'status'      => 'active',
                'invited_at'  => now(),
                'accepted_at' => null,
                'invited_by'  => $invitedBy->id,
            ]);

            return $existing->refresh();
        }

        return GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'status'      => 'active',
            'invited_at'  => now(),
            'invited_by'  => $invitedBy->id,
        ]);
    }
}
