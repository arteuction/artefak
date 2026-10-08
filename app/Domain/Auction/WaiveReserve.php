<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\AuctionItem;
use App\Models\Reserve;
use Illuminate\Support\Facades\DB;

/**
 * Seller (or admin) accepts the highest bid despite reserve not being met.
 *
 * Transitions:
 *   Reserve:     not_reached → waived
 *   AuctionItem: reserve_not_met → sold (winning_bid_id set, payment deadline started)
 */
final class WaiveReserve
{
    public function execute(Reserve $reserve, int $decidedBy, ?string $notes = null): void
    {
        if ($reserve->status !== 'not_reached') {
            throw new \InvalidArgumentException(
                "Reserve #{$reserve->id} is already resolved (status: {$reserve->status})."
            );
        }

        DB::transaction(function () use ($reserve, $decidedBy, $notes): void {
            $reserve->update([
                'status'     => 'waived',
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'notes'      => $notes,
            ]);

            /** @var AuctionItem $item */
            $item = AuctionItem::lockForUpdate()->findOrFail($reserve->auction_item_id);

            $winner = $item->bids()
                ->where('status', 'accepted')
                ->orderByDesc('amount_cents')
                ->first();

            if ($winner === null) {
                throw new \LogicException("No accepted bid found on item #{$item->id} to waive reserve for.");
            }

            $item->update([
                'status'             => 'sold',
                'winning_bid_id'     => $winner->id,
                'fulfillment_status' => 'awaiting_payment',
                'payment_deadline'   => now()->addHours(48),
            ]);

            $winner->update(['status' => 'won']);

            (new AppendDomainEvent())->execute(
                aggregate: $reserve,
                eventType: 'reserve.waived',
                payload: [
                    'auction_item_id'    => $reserve->auction_item_id,
                    'decided_by'         => $decidedBy,
                    'highest_bid_cents'  => $reserve->highest_bid_cents,
                    'reserve_price_cents' => $reserve->reserve_price_cents,
                ],
                idempotencyKey: "reserve.waived:{$reserve->id}",
            );
        });
    }
}
