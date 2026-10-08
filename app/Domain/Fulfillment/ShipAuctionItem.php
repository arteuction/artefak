<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Models\AuctionFulfillment;
use App\Models\AuctionItem;
use InvalidArgumentException;

final class ShipAuctionItem
{
    public function execute(
        AuctionItem $item,
        string      $carrier,
        string      $trackingNumber,
        ?string     $notes = null,
    ): AuctionFulfillment {
        if ($item->fulfillment_status !== 'paid') {
            throw new InvalidArgumentException(
                "Item must be paid before shipping (status: {$item->fulfillment_status})."
            );
        }

        $fulfillment = $item->fulfillment;
        if ($fulfillment === null) {
            throw new InvalidArgumentException('No fulfillment record found for this auction item.');
        }

        $fulfillment->update([
            'carrier'         => $carrier,
            'tracking_number' => $trackingNumber,
            'shipped_at'      => now(),
            'notes'           => $notes ?? $fulfillment->notes,
        ]);

        $item->update(['fulfillment_status' => 'shipped']);

        return $fulfillment->fresh();
    }
}
