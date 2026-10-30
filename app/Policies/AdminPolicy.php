<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Gate for pure admin/operator operations that have no associated model
 * (ledger reads, reconciliation, split-profiles, transfer-outbox, user management).
 *
 * Registered as a Gate ability, not a model Policy, because there is no
 * single Eloquent model to attach it to:
 *   Gate::define('admin-area', [AdminPolicy::class, 'adminArea']);
 */
final class AdminPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** Full back-office access — admin and operator only. */
    public function adminArea(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }
}
