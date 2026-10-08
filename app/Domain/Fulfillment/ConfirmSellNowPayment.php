<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

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

        return $offer->fresh();
    }
}
