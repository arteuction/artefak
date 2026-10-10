<?php

declare(strict_types=1);

namespace App\Domain\Governance;

use App\Models\GovernancePolicy;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Record explicit user consent to a governance policy version.
 * Idempotent: upserts on (user_id, governance_policy_id).
 */
final class RecordConsent
{
    public function execute(User $user, GovernancePolicy $policy, Request $request): UserConsent
    {
        if (! $policy->is_active) {
            throw new InvalidArgumentException("Policy {$policy->type} v{$policy->version} is not active.");
        }

        $consent = UserConsent::updateOrCreate(
            [
                'user_id'               => $user->id,
                'governance_policy_id'  => $policy->id,
            ],
            [
                'consented_at'  => now(),
                'ip_address'    => $request->ip(),
                'user_agent'    => substr((string) $request->userAgent(), 0, 512),
                'withdrawn_at'  => null,
            ],
        );

        return $consent;
    }

    public function withdraw(User $user, GovernancePolicy $policy): void
    {
        UserConsent::where('user_id', $user->id)
            ->where('governance_policy_id', $policy->id)
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now()]);
    }
}
