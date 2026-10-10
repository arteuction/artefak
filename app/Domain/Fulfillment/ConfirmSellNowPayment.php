<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\SellNowOffer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ConfirmSellNowPayment
{
    public function execute(SellNowOffer $offer): SellNowOffer
    {
        return DB::transaction(function () use ($offer): SellNowOffer {
            // Pessimistic lock prevents duplicate webhook races
            $locked = SellNowOffer::lockForUpdate()->findOrFail($offer->id);

            if ($locked->status === 'paid') {
                return $locked; // idempotent — already paid
            }

            if ($locked->status !== 'accepted') {
                throw new InvalidArgumentException(
                    "Offer must be accepted before confirming payment (status: {$locked->status})."
                );
            }

            $locked->update(['status' => 'paid']);

            $artLot = $locked->artLot;
            if ($artLot) {
                (new AppendDomainEvent())->execute(
                    aggregate: $artLot,
                    eventType: 'payment.confirmed',
                    payload: [
                        'channel'            => 'sell_now',
                        'offer_id'           => $locked->id,
                        'agreed_price_cents' => $locked->agreed_price_cents,
                        'currency'           => $locked->currency,
                        'buyer_id'           => $locked->buyer_id,
                    ],
                    idempotencyKey: "payment.confirmed:sell_now:{$locked->id}",
                );
            }

            return $locked->fresh();
        });
    }
}
