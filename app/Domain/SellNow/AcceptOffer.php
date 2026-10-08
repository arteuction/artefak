<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

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

        return $offer->fresh();
    }
}
