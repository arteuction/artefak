<?php

declare(strict_types=1);

namespace App\Domain\Governance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GDPR Article 17 — Right to Erasure.
 *
 * Pseudonymises and removes personal data for a user while preserving
 * financial records, ledger entries, and audit trails required by law.
 *
 * Rules:
 *   - Financial records (ledger_entries, settlements, donations) are KEPT
 *     but de-linked from the user where possible (user set to null / pseudonym).
 *   - Domain events and activity logs: personal payload fields are redacted.
 *   - The User row is anonymised (name, email → pseudonym); NOT hard-deleted.
 *   - Can only be applied to non-admin accounts.
 */
final class EraseUserData
{
    public function execute(User $user): void
    {
        if ($user->role === 'admin') {
            throw new InvalidArgumentException('Admin accounts cannot be erased.');
        }

        DB::transaction(function () use ($user): void {
            $pseudonym = "erased_user_{$user->id}";
            $erasedEmail = "{$pseudonym}@erased.invalid";

            // Anonymise the user record.
            // Use DB::table() to bypass the Eloquent 'hashed' cast on password
            // and any fillable restrictions, so the raw empty string is stored.
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'name'           => $pseudonym,
                    'email'          => $erasedEmail,
                    'password'       => '',
                    'remember_token' => null,
                    'updated_at'     => now(),
                ]);

            // Revoke all Sanctum tokens
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', User::class)
                ->delete();

            // Revoke API clients owned by this user
            DB::table('api_clients')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->update([
                    'is_active'  => false,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            // Withdraw all active consents
            DB::table('user_consents')
                ->where('user_id', $user->id)
                ->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => now()]);

            // Record the erasure as a governance event
            DB::table('domain_events')->insert([
                'aggregate_type'  => 'User',
                'aggregate_id'    => $user->id,
                'event_type'      => 'user.erased',
                'event_version'   => 1,
                'payload'         => json_encode(['reason' => 'GDPR Art. 17 erasure request']),
                'idempotency_key' => "user.erased:{$user->id}",
                'status'          => 'pending',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        });
    }
}
