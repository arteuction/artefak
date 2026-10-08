<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\SellNowOffer;
use InvalidArgumentException;

final class ConfirmSellNowPayment
{
    public function execute(SellNowOffer $offer): SellNowOffer
    {
        if ($offer->status !== 'accepted') {
            throw new InvalidArgumentException(
                "Offer must be accepted before confirming payment (status: {$offer->status})."
            );
        }

        $offer->update(['status' => 'paid']);

        $artLot = $offer->artLot;
        if ($artLot) {
            (new AppendDomainEvent())->execute(
                aggregate: $artLot,
                eventType: 'payment.confirmed',
                payload: [
                    'channel'            => 'sell_now',
                    'offer_id'           => $offer->id,
                    'agreed_price_cents' => $offer->agreed_price_cents,
                    'currency'           => $offer->currency,
                    'buyer_id'           => $offer->buyer_id,
                ],
                idempotencyKey: "payment.confirmed:sell_now:{$offer->id}",
            );
        }

        return $offer->fresh();
    }
}
