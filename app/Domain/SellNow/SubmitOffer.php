<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(
            fn () => $this->doExecute($artLot, $buyer, $offeredPriceCents, $galleryId, $notes)
        );
    }

    private function doExecute(
        ArtLot $artLot,
        User $buyer,
        int $offeredPriceCents,
        ?int $galleryId,
        ?string $notes,
    ): SellNowOffer {
        if ($artLot->status !== 'active') {
            throw new InvalidArgumentException("ArtLot is not available for offers (status: {$artLot->status}).");
        }

        if ($offeredPriceCents <= 0) {
            throw new InvalidArgumentException('Offered price must be positive.');
        }

        // Prevent duplicate active offers from the same buyer on the same lot
        $alreadyActive = SellNowOffer::where('art_lot_id', $artLot->id)
            ->where('buyer_id', $buyer->id)
            ->whereNotIn('status', ['rejected', 'expired', 'closed'])
            ->lockForUpdate()
            ->exists();

        if ($alreadyActive) {
            throw new InvalidArgumentException('You already have an active offer on this lot.');
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

