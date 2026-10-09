<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the public auction channel when a lot's status changes:
 * open → sold, reserve_not_met, passed, canceled.
 *
 * Channel: public auction.{auctionId}
 * Safe to expose: no prices above the final hammer, no bidder identities.
 */
final class AuctionItemStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $auctionItemId,
        public readonly int    $auctionId,
        public readonly string $newStatus,
        public readonly ?int   $hammerPriceCents = null,
        public readonly ?string $currency = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("auction.{$this->auctionId}")];
    }

    public function broadcastAs(): string
    {
        return 'auction-item.status-changed';
    }
}
