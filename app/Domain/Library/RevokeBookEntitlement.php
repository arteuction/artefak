<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Models\BookEntitlement;
use App\Models\BookPurchase;

/**
 * Revokes book access.
 *
 * On full refund: revoke the paid entitlement.
 * On partial refund: entitlement stays active — policy decision.
 * Admin may revoke any active entitlement.
 *
 * Idempotent: already-revoked entitlements are left unchanged.
 */
final class RevokeBookEntitlement
{
    public function forRefund(BookPurchase $purchase): void
    {
        BookEntitlement::where('book_purchase_id', $purchase->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function forAdmin(BookEntitlement $entitlement): void
    {
        if ($entitlement->revoked_at === null) {
            $entitlement->update(['revoked_at' => now()]);
        }
    }
}
