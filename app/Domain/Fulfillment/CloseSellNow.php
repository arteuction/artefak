<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Models\ArtLot;
use App\Models\OwnershipTransfer;
use App\Models\SellNowOffer;
use InvalidArgumentException;

final class CloseSellNow
{
    public function execute(SellNowOffer $offer, ?string $notes = null): SellNowOffer
    {
        if ($offer->status !== 'delivered') {
            throw new InvalidArgumentException(
                "Offer must be delivered before closing (status: {$offer->status})."
            );
        }

        $offer->update([
            'status' => 'closed',
            'notes'  => $notes ?? $offer->notes,
        ]);

        // Mark the ArtLot as sold
        $artLot = $offer->artLot;
        if ($artLot !== null) {
            $artLot->update(['status' => 'sold']);
        }

        // Record provenance step
        OwnershipTransfer::create([
            'art_lot_id'           => $offer->art_lot_id,
            'from_user_id'         => $artLot?->consignor_id,
            'to_user_id'           => $offer->buyer_id,
            'sell_now_offer_id'    => $offer->id,
            'transfer_price_cents' => $offer->agreed_price_cents ?? $offer->offered_price_cents,
            'currency'             => $offer->currency,
            'channel'              => 'sell_now',
            'transferred_at'       => now(),
            'notes'                => $notes,
        ]);

        return $offer->fresh();
    }
}
