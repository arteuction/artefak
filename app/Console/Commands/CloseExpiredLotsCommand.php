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
        $items = AuctionItem::where('status', 'open')
            ->whereHas('auction', fn ($q) => $q->where('ends_at', '<=', now()))
            ->pluck('id');

        foreach ($items as $id) {
            CloseAuctionItemJob::dispatch($id);
        }

        $this->info("Dispatched {$items->count()} close job(s).");

        return self::SUCCESS;
    }
}
