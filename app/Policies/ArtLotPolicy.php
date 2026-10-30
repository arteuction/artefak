<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArtLot;
use App\Models\User;

final class ArtLotPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** Update lot metadata — only while in draft (owner/consignor or staff). */
    public function update(User $user, ArtLot $lot): bool
    {
        return $lot->consignor_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /**
     * Perform a state-machine transition.
     * Some transitions additionally require gallery-staff check — handled in the controller
     * after this policy passes, because it requires a DB lookup.
     */
    public function transition(User $user, ArtLot $lot): bool
    {
        return $lot->consignor_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Gallery-staff or admin may approve/advance gallery-specific transitions. */
    public function galleryTransition(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }
}
