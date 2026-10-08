<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Asset\CloseArtLot;
use App\Models\ArtLot;
use App\Models\SellNowOffer;
use App\Models\User;
use InvalidArgumentException;

final class PurchaseAtFixedPrice
{
    public function execute(ArtLot $artLot, User $buyer, ?int $galleryId = null): SellNowOffer
    {
        if ($artLot->status !== 'active') {
            throw new InvalidArgumentException("ArtLot is not available (status: {$artLot->status}).");
        }

        if ($artLot->buy_now_price_cents === null) {
            throw new InvalidArgumentException('ArtLot has no fixed buy-now price set.');
        }

        // Hybrid lots: buy-now window may have expired
        if ($artLot->buy_now_expires_at !== null && now()->gt($artLot->buy_now_expires_at)) {
            throw new InvalidArgumentException('The Buy Now window for this lot has expired.');
        }

        $price = $artLot->buy_now_price_cents;

        $offer = SellNowOffer::create([
            'art_lot_id'          => $artLot->id,
            'buyer_id'            => $buyer->id,
            'gallery_id'          => $galleryId,
            'offered_price_cents' => $price,
            'agreed_price_cents'  => $price,
            'currency'            => $artLot->currency ?? 'EUR',
            'status'              => 'accepted',
        ]);

        (new CloseArtLot())->execute(
            artLot:         $artLot,
            outcome:        CloseArtLot::STATUS_SOLD,
            soldPriceCents: $price,
            buyerId:        $buyer->id,
            idempotencyKey: "art_lot.sold.purchase_now:{$artLot->id}:{$offer->id}",
        );

        return $offer;
    }
}
