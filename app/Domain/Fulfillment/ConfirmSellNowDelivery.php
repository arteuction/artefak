<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\SellNowOffer;
use InvalidArgumentException;

final class ConfirmSellNowDelivery
{
    public function execute(SellNowOffer $offer): SellNowOffer
    {
        if ($offer->status !== 'paid') {
            throw new InvalidArgumentException(
                "Offer must be paid before confirming delivery (status: {$offer->status})."
            );
        }

        $offer->update(['status' => 'delivered']);

        $artLot = $offer->artLot;
        if ($artLot) {
            (new AppendDomainEvent())->execute(
                aggregate: $artLot,
                eventType: 'artwork.delivered',
                payload: [
                    'channel'  => 'sell_now',
                    'offer_id' => $offer->id,
                    'buyer_id' => $offer->buyer_id,
                ],
                idempotencyKey: "artwork.delivered:sell_now:{$offer->id}",
            );
        }

        return $offer->fresh();
    }
}
