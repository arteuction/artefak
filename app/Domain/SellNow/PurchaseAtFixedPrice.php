<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

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

        return SellNowOffer::create([
            'art_lot_id'          => $artLot->id,
            'buyer_id'            => $buyer->id,
            'gallery_id'          => $galleryId,
            'offered_price_cents' => $artLot->buy_now_price_cents,
            'agreed_price_cents'  => $artLot->buy_now_price_cents,
            'currency'            => $artLot->currency ?? 'EUR',
            'status'              => 'accepted',
        ]);
    }
}
