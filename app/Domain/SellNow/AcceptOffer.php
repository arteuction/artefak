<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Asset\CloseArtLot;
use App\Domain\Outbox\AppendDomainEvent;
use App\Models\SellNowOffer;
use InvalidArgumentException;

final class AcceptOffer
{
    public function execute(SellNowOffer $offer): SellNowOffer
    {
        if (! in_array($offer->status, ['submitted', 'countered'], true)) {
            throw new InvalidArgumentException("Cannot accept offer in status: {$offer->status}.");
        }

        $agreedPrice = $offer->status === 'countered'
            ? $offer->counter_price_cents
            : $offer->offered_price_cents;

        $offer->update([
            'agreed_price_cents' => $agreedPrice,
            'status'             => 'accepted',
        ]);

        $artLot = $offer->artLot;
        if ($artLot) {
            (new AppendDomainEvent())->execute(
                aggregate: $artLot,
                eventType: 'offer.accepted',
                payload: [
                    'offer_id'           => $offer->id,
                    'buyer_id'           => $offer->buyer_id,
                    'agreed_price_cents' => $agreedPrice,
                    'currency'           => $offer->currency,
                ],
                idempotencyKey: "offer.accepted:{$offer->id}",
            );

            (new CloseArtLot())->execute(
                artLot:         $artLot,
                outcome:        CloseArtLot::STATUS_SOLD,
                soldPriceCents: $agreedPrice,
                buyerId:        $offer->buyer_id,
                idempotencyKey: "art_lot.sold.offer:{$artLot->id}:{$offer->id}",
            );
        }

        return $offer->fresh();
    }
}
