<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Bid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to all bidders watching an auction item.
 *
 * Channel: public auction.{auction_id}
 *   — no auth required; any connected browser can subscribe.
 *   — suitable for live bid ticker displays.
 *
 * The private auction-item.{item_id} channel carries the same payload
 * but is restricted to authenticated users (see routes/channels.php).
 *
 * Browser timer is display-only.  Server-authoritative clock enforcement
 * lives in PlaceBid::isOpenForBidding().
 */
final class BidPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly int    $bidId;
    public readonly int    $auctionId;
    public readonly int    $auctionItemId;
    public readonly int    $amountCents;
    public readonly string $currency;
    public readonly int    $nextBidCents;

    public function __construct(Bid $bid)
    {
        $item = $bid->auctionItem;

        $this->bidId          = $bid->id;
        $this->auctionId      = $item->auction_id;
        $this->auctionItemId  = $item->id;
        $this->amountCents    = $bid->amount_cents;
        $this->currency       = $item->auction?->currency ?? 'EUR';
        $this->nextBidCents   = $item->nextBidCents();
    }

    /** @return Channel[] */
    public function broadcastOn(): array
    {
        return [
            new Channel("auction.{$this->auctionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bid.placed';
    }
}
