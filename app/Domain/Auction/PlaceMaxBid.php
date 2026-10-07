<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\AuctionItem;
use App\Models\MaxBid;
use Illuminate\Support\Facades\DB;

/**
 * Stores a bidder's maximum bid ceiling on an open auction item.
 *
 * The ceiling is kept private — other bidders never see it.
 * A user may hold at most ONE active max bid per item; calling this
 * again replaces the previous one if the new ceiling is higher.
 *
 * Rules:
 *   - Item must be 'open'.
 *   - Ceiling must be >= item's current nextBidCents().
 *   - A lower ceiling than the existing active max bid is rejected.
 *
 * Does NOT place a bid automatically; proxy resolution is a separate
 * concern handled when a competing bid arrives.
 */
final class PlaceMaxBid
{
    public function execute(
        AuctionItem $item,
        int         $bidderId,
        int         $ceilingCents,
    ): MaxBid {
        return DB::transaction(function () use ($item, $bidderId, $ceilingCents): MaxBid {
            /** @var AuctionItem $locked */
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->status !== 'open') {
                throw new BidRejected("Lot #{$locked->lot_number} is not open for bidding.");
            }

            $minimum = $locked->nextBidCents();
            if ($ceilingCents < $minimum) {
                throw new BidRejected(
                    "Max bid ceiling of {$ceilingCents} cents is below the minimum bid of {$minimum} cents."
                );
            }

            // Check for an existing active max bid by this user on this item
            $existing = MaxBid::where('auction_item_id', $locked->id)
                ->where('user_id', $bidderId)
                ->where('status', 'active')
                ->first();

            if ($existing !== null) {
                if ($ceilingCents <= $existing->ceiling_cents) {
                    throw new BidRejected(
                        "New ceiling of {$ceilingCents} cents must exceed the current max bid of {$existing->ceiling_cents} cents."
                    );
                }
                // Cancel the old one and replace
                $existing->cancel();
            }

            return MaxBid::create([
                'auction_item_id' => $locked->id,
                'user_id'         => $bidderId,
                'ceiling_cents'   => $ceilingCents,
                'currency'        => $locked->auction->currency ?? 'EUR',
                'status'          => 'active',
            ]);
        });
    }
}
