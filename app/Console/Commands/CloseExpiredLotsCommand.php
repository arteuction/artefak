<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CloseAuctionItemJob;
use App\Models\AuctionItem;
use Illuminate\Console\Command;

/**
 * Dispatches CloseAuctionItemJob for every open lot whose auction has ended.
 * Run every minute via the scheduler.
 */
final class CloseExpiredLotsCommand extends Command
{
    protected $signature   = 'auction:close-expired-lots';
    protected $description = 'Dispatch close jobs for open lots past their auction end time.';

    public function handle(): int
    {
        $dispatched = 0;

        // join is more efficient than whereHas correlated subquery at scale
        AuctionItem::join('auctions', 'auctions.id', '=', 'auction_items.auction_id')
            ->where('auction_items.status', 'open')
            ->where('auctions.ends_at', '<=', now())
            ->select('auction_items.id')
            ->chunkById(200, function ($items) use (&$dispatched): void {
                foreach ($items as $item) {
                    CloseAuctionItemJob::dispatch($item->id);
                    $dispatched++;
                }
            }, 'auction_items.id', 'id');

        $this->info("Dispatched {$dispatched} close job(s).");

        return self::SUCCESS;
    }
}
