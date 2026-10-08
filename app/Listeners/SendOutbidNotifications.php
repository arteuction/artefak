<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BidPlaced;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When a new bid is placed, notify all previously outbid users on that item.
 *
 * Runs async (ShouldQueue) so a bid acknowledgment is never delayed by email.
 * Deduplicates by user: one email per outbid user per bid event.
 */
final class SendOutbidNotifications implements ShouldQueue
{
    public function handle(BidPlaced $event): void
    {
        // Find the just-placed bid's owner so we don't notify them they outbid themselves
        $placerUserId = Bid::where('id', $event->bidId)->value('user_id');

        $outbidUserIds = Bid::where('auction_item_id', $event->auctionItemId)
            ->where('status', 'outbid')
            ->when($placerUserId, fn ($q) => $q->where('user_id', '!=', $placerUserId))
            ->distinct('user_id')
            ->pluck('user_id');

        $users = User::whereIn('id', $outbidUserIds)->get();

        foreach ($users as $user) {
            $user->notify(new OutbidNotification(
                item:            \App\Models\AuctionItem::find($event->auctionItemId),
                currentBidCents: $event->amountCents,
                nextBidCents:    $event->nextBidCents,
                currency:        $event->currency,
            ));
        }
    }
}
