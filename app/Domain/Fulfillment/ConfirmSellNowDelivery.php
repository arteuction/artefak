<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

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

        return $offer->fresh();
    }
}
