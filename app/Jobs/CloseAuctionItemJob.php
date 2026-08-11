<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Auction\CloseAuctionItem;
use App\Models\AuctionItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a single auction lot when its bidding window expires.
 * Dispatched by CloseExpiredLotsCommand for each open lot past ends_at.
 */
final class CloseAuctionItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $auctionItemId,
    ) {}

    public function handle(CloseAuctionItem $closeAuctionItem): void
    {
        $item = AuctionItem::find($this->auctionItemId);

        if ($item === null) {
            return;
        }

        $closeAuctionItem->execute($item);
    }
}
