<?php

declare(strict_types=1);

namespace App\Domain\Gallery;

use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;

/**
 * Revoke a staff member's role at a gallery.
 *
 * Rules:
 *   - Only an owner can revoke staff.
 *   - An owner cannot revoke their own owner role if they are the last owner
 *     (would leave the gallery with no owner).
 *   - Revoking a non-existent or already-revoked role is a no-op (idempotent).
 */
final class RevokeGalleryStaff
{
    public function execute(
        Gallery $gallery,
        User    $target,
        string  $role,
        User    $revokedBy,
    ): void {
        if (! $gallery->hasRole($revokedBy, 'owner')) {
            throw new \DomainException(
                "Only gallery owners can revoke staff (user #{$revokedBy->id} is not an owner)."
            );
        }

        // Guard: cannot remove the last owner
        if ($role === 'owner' && $target->id === $revokedBy->id) {
            $ownerCount = GalleryStaff::where('gallery_id', $gallery->id)
                ->where('role', 'owner')
                ->where('status', 'active')
                ->count();

            if ($ownerCount <= 1) {
                throw new \DomainException(
                    'Cannot revoke the last owner of a gallery.'
                );
            }
        }

        GalleryStaff::where('gallery_id', $gallery->id)
            ->where('user_id', $target->id)
            ->where('role', $role)
            ->where('status', 'active')
            ->update(['status' => 'revoked']);
    }
}
