<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\AuctionItem;
use App\Models\Reserve;

/**
 * Evaluates whether the reserve price was met after bidding closes.
 *
 * Called by CloseAuctionItem when reserve_enabled = true and the highest
 * bid is below the art lot's reserve_price_cents.
 *
 * Creates a Reserve record (status = not_reached) and sets the auction
 * item status to reserve_not_met.  The lot stays in seller review until
 * one of WaiveReserve, ApproveReserve, IssueCounterOffer, or DeclineReserve
 * resolves it.
 */
final class EvaluateReserve
{
    public function execute(AuctionItem $item, int $highestBidCents): Reserve
    {
        $reservePrice = $item->artLot?->reserve_price_cents
            ?? throw new \LogicException("ArtLot has no reserve_price_cents for item #{$item->id}");

        $item->update(['status' => 'reserve_not_met']);

        $reserve = Reserve::create([
            'auction_item_id'     => $item->id,
            'reserve_price_cents' => $reservePrice,
            'highest_bid_cents'   => $highestBidCents,
            'status'              => 'not_reached',
        ]);

        (new AppendDomainEvent())->execute(
            aggregate: $item,
            eventType: 'reserve.not_met',
            payload: [
                'reserve_id'          => $reserve->id,
                'reserve_price_cents' => $reservePrice,
                'highest_bid_cents'   => $highestBidCents,
                'auction_id'          => $item->auction_id,
            ],
            idempotencyKey: "reserve.not_met:{$item->id}",
        );

        return $reserve;
    }
}
