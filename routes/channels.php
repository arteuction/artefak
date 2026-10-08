<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorizations
|--------------------------------------------------------------------------
|
| auction.{auctionId}        — public; no auth required.
|                               Any browser can receive live bid tickers.
|
| auction-item.{itemId}      — private; authenticated users only.
|                               Used for per-lot push updates (next bid
|                               amount, time extension, status changes).
|
*/

// Public auction channel — used by BidPlaced event.
// No auth callback needed for Channel (as opposed to PrivateChannel).

// Private per-item channel — authenticated users only.
Broadcast::channel('auction-item.{itemId}', function ($user, int $itemId): bool {
    // Any authenticated user may subscribe to watch a lot's updates.
    return $user !== null;
});
