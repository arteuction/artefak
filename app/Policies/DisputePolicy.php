<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

final class DisputePolicy
{
    private const STAFF = ['admin', 'operator'];

    /** List all disputes — staff only. */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** View a specific dispute — the opener or staff. */
    public function view(User $user, Dispute $dispute): bool
    {
        return $dispute->opened_by === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Assign a dispute to a handler — staff only. */
    public function assign(User $user, Dispute $dispute): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** Resolve a dispute — staff only. */
    public function resolve(User $user, Dispute $dispute): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    /** Attach evidence — the opener or staff. */
    public function attachEvidence(User $user, Dispute $dispute): bool
    {
        return $dispute->opened_by === $user->id
            || in_array($user->role, self::STAFF, true);
    }
}
