<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Asset\CloseArtLot;
use App\Domain\Outbox\AppendDomainEvent;
use App\Models\SellNowOffer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AcceptOffer
{
    public function execute(SellNowOffer $offer): SellNowOffer
    {
        return DB::transaction(function () use ($offer): SellNowOffer {
            $locked = SellNowOffer::lockForUpdate()->findOrFail($offer->id);

            if ($locked->status === 'accepted') {
                return $locked; // idempotent
            }

            if (! in_array($locked->status, ['submitted', 'countered'], true)) {
                throw new InvalidArgumentException("Cannot accept offer in status: {$locked->status}.");
            }

            $agreedPrice = $locked->status === 'countered'
                ? $locked->counter_price_cents
                : $locked->offered_price_cents;

            $locked->update([
                'agreed_price_cents' => $agreedPrice,
                'status'             => 'accepted',
            ]);

            $artLot = $locked->artLot;
            if ($artLot) {
                (new AppendDomainEvent())->execute(
                    aggregate: $artLot,
                    eventType: 'offer.accepted',
                    payload: [
                        'offer_id'           => $locked->id,
                        'buyer_id'           => $locked->buyer_id,
                        'agreed_price_cents' => $agreedPrice,
                        'currency'           => $locked->currency,
                    ],
                    idempotencyKey: "offer.accepted:{$locked->id}",
                );

                (new CloseArtLot())->execute(
                    artLot:         $artLot,
                    outcome:        CloseArtLot::STATUS_SOLD,
                    soldPriceCents: $agreedPrice,
                    buyerId:        $locked->buyer_id,
                    idempotencyKey: "art_lot.sold.offer:{$artLot->id}:{$locked->id}",
                );
            }

            return $locked->fresh();
        });
    }
}
