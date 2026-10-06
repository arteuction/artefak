<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\AuctionFulfillment;
use App\Models\AuctionItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manages fulfillment state transitions for a sold auction lot.
 *
 * Allowed transitions:
 *   paid → preparing  (seller starts packaging)
 *   preparing → shipped  (tracking number recorded)
 *   shipped → delivered  (buyer confirms receipt)
 *
 * Each method is idempotent for the same target status.
 */
final class FulfillAuctionItem
{
    /**
     * Record the winning buyer's shipping address.
     * Creates the fulfillment row (called after payment is confirmed).
     */
    public function createRecord(AuctionItem $item, int $winnerId, array $address): AuctionFulfillment
    {
        return DB::transaction(function () use ($item, $winnerId, $address): AuctionFulfillment {
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->fulfillment_status !== 'paid') {
                throw new InvalidArgumentException(
                    "Cannot create fulfillment for item [{$locked->id}] in status [{$locked->fulfillment_status}]."
                );
            }

            $locked->fulfillment_status = 'preparing';
            $locked->save();

            return AuctionFulfillment::create([
                'auction_item_id'      => $locked->id,
                'winner_user_id'       => $winnerId,
                'shipping_name'        => $address['name'],
                'shipping_line1'       => $address['line1'],
                'shipping_line2'       => $address['line2'] ?? null,
                'shipping_city'        => $address['city'],
                'shipping_postal_code' => $address['postal_code'],
                'shipping_country'     => strtoupper($address['country']),
                'notes'                => $address['notes'] ?? null,
            ]);
        });
    }

    /**
     * Record shipment with carrier and tracking number.
     */
    public function markShipped(AuctionItem $item, string $carrier, string $trackingNumber): void
    {
        DB::transaction(function () use ($item, $carrier, $trackingNumber): void {
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->fulfillment_status === 'shipped') {
                return; // idempotent
            }

            if ($locked->fulfillment_status !== 'preparing') {
                throw new InvalidArgumentException(
                    "Cannot mark shipped: item [{$locked->id}] is in status [{$locked->fulfillment_status}]."
                );
            }

            $locked->fulfillment_status = 'shipped';
            $locked->save();

            AuctionFulfillment::where('auction_item_id', $locked->id)->update([
                'carrier'         => $carrier,
                'tracking_number' => $trackingNumber,
                'shipped_at'      => now(),
            ]);
        });
    }

    /**
     * Mark the item as delivered.
     */
    public function markDelivered(AuctionItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->fulfillment_status === 'delivered') {
                return; // idempotent
            }

            if ($locked->fulfillment_status !== 'shipped') {
                throw new InvalidArgumentException(
                    "Cannot mark delivered: item [{$locked->id}] is in status [{$locked->fulfillment_status}]."
                );
            }

            $locked->fulfillment_status = 'delivered';
            $locked->save();

            AuctionFulfillment::where('auction_item_id', $locked->id)->update([
                'delivered_at' => now(),
            ]);
        });
    }
}
