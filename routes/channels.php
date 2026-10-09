<?php

declare(strict_types=1);

use App\Models\GalleryStaff;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorizations
|--------------------------------------------------------------------------
|
| Public channels (Channel) require no authorization callback.
| Private channels (PrivateChannel) run the callback; return true/false.
| Presence channels (PresenceChannel) return user data or falsy.
|
| Channel taxonomy:
|
|  auction.{auctionId}          PUBLIC   — live bid ticker for any browser
|  auction-item.{itemId}        PRIVATE  — per-lot updates for auth users
|  bidder.{userId}              PRIVATE  — outbid / winner notifications (self only)
|  gallery.{galleryId}          PRIVATE  — gallery staff operational alerts
|  admin                        PRIVATE  — platform-wide ops alerts (admin/operator)
|
*/

// ── auction.{auctionId} ───────────────────────────────────────────────────────
// Public channel — no auth callback needed. Any browser may subscribe.
// Payload: current bid, next valid bid, anti-sniping extensions, lot status.
// MUST NOT carry max-bid ceilings, bidder identity, or financial account info.

// ── auction-item.{itemId} ────────────────────────────────────────────────────
// Authenticated users only — used for detailed per-lot updates.
Broadcast::channel('auction-item.{itemId}', function ($user, int $itemId): bool {
    return $user !== null;
});

// ── bidder.{userId} ──────────────────────────────────────────────────────────
// Only the user themselves can subscribe to their outbid/winner notifications.
Broadcast::channel('bidder.{userId}', function ($user, int $userId): bool {
    return (int) $user->id === $userId;
});

// ── gallery.{galleryId} ──────────────────────────────────────────────────────
// Active gallery staff or admin/operator may subscribe to a gallery's
// operational alert channel.
Broadcast::channel('gallery.{galleryId}', function ($user, int $galleryId): bool {
    if (in_array($user->role, ['admin', 'operator'], true)) {
        return true;
    }

    return GalleryStaff::where('gallery_id', $galleryId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();
});

// ── admin ────────────────────────────────────────────────────────────────────
// Platform-wide operational alerts: failed Stripe transfers, reconciliation
// mismatches, stuck domain events, overdue shipments, etc.
Broadcast::channel('admin', function ($user): bool {
    return in_array($user->role, ['admin', 'operator'], true);
});
