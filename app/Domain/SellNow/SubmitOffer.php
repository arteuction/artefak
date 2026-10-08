<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;
use App\Models\SellNowOffer;
use App\Models\User;
use InvalidArgumentException;

final class SubmitOffer
{
    public function execute(
        ArtLot $artLot,
        User $buyer,
        int $offeredPriceCents,
        ?int $galleryId = null,
        ?string $notes = null,
    ): SellNowOffer {
        if ($artLot->status !== 'active') {
            throw new InvalidArgumentException("ArtLot is not available for offers (status: {$artLot->status}).");
        }

        if ($offeredPriceCents <= 0) {
            throw new InvalidArgumentException('Offered price must be positive.');
        }

        $offer = SellNowOffer::create([
            'art_lot_id'          => $artLot->id,
            'buyer_id'            => $buyer->id,
            'gallery_id'          => $galleryId,
            'offered_price_cents' => $offeredPriceCents,
            'currency'            => $artLot->currency ?? 'EUR',
            'status'              => 'submitted',
            'notes'               => $notes,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate: $artLot,
            eventType: 'offer.submitted',
            payload: [
                'offer_id'            => $offer->id,
                'buyer_id'            => $buyer->id,
                'offered_price_cents' => $offeredPriceCents,
                'currency'            => $offer->currency,
            ],
            idempotencyKey: "offer.submitted:{$offer->id}",
        );

        return $offer;
    }
}
