<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\AuctionFulfillment;
use App\Models\AuctionItem;
use App\Models\OwnershipTransfer;
use InvalidArgumentException;

final class ConfirmAuctionDelivery
{
    public function execute(AuctionItem $item, ?string $notes = null): AuctionFulfillment
    {
        if ($item->fulfillment_status !== 'shipped') {
            throw new InvalidArgumentException(
                "Item must be shipped before confirming delivery (status: {$item->fulfillment_status})."
            );
        }

        $fulfillment = $item->fulfillment;
        if ($fulfillment === null) {
            throw new InvalidArgumentException('No fulfillment record found for this auction item.');
        }

        $fulfillment->update([
            'delivered_at' => now(),
            'notes'        => $notes ?? $fulfillment->notes,
        ]);

        $item->update(['fulfillment_status' => 'delivered']);

        // Record provenance step
        $bid = $item->winningBid;
        OwnershipTransfer::create([
            'art_lot_id'           => $item->art_lot_id,
            'from_user_id'         => $item->artLot?->consignor_id,
            'to_user_id'           => $fulfillment->winner_user_id,
            'auction_item_id'      => $item->id,
            'transfer_price_cents' => $bid?->amount_cents ?? 0,
            'currency'             => $item->artLot?->currency ?? 'EUR',
            'channel'              => 'auction',
            'transferred_at'       => now(),
        ]);

        $artLot = $item->artLot;
        if ($artLot !== null) {
            (new AppendDomainEvent())->execute(
                aggregate: $artLot,
                eventType: 'artwork.delivered',
                payload: [
                    'channel'         => 'auction',
                    'auction_item_id' => $item->id,
                    'buyer_id'        => $fulfillment->winner_user_id,
                ],
                idempotencyKey: "artwork.delivered:auction:{$item->id}",
            );

            (new AppendDomainEvent())->execute(
                aggregate: $artLot,
                eventType: 'ownership.transferred',
                payload: [
                    'channel'              => 'auction',
                    'auction_item_id'      => $item->id,
                    'from_user_id'         => $artLot->consignor_id,
                    'to_user_id'           => $fulfillment->winner_user_id,
                    'transfer_price_cents' => $bid?->amount_cents ?? 0,
                    'currency'             => $artLot->currency ?? 'EUR',
                ],
                idempotencyKey: "ownership.transferred:auction:{$item->id}",
            );
        }

        return $fulfillment->fresh();
    }
}
