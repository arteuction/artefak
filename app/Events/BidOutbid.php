<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the outbid bidder's private channel when they lose the leading position.
 *
 * Channel: private bidder.{userId}
 * Only the affected bidder can receive this event.
 */
final class BidOutbid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $userId,
        public readonly int    $auctionItemId,
        public readonly int    $auctionId,
        public readonly int    $previousBidCents,
        public readonly int    $newLeadingBidCents,
        public readonly string $currency,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("bidder.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'bid.outbid';
    }
}
