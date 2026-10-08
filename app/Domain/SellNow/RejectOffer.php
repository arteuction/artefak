<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Models\SellNowOffer;
use InvalidArgumentException;

final class RejectOffer
{
    public function execute(SellNowOffer $offer, ?string $notes = null): SellNowOffer
    {
        if (! in_array($offer->status, ['submitted', 'countered'], true)) {
            throw new InvalidArgumentException("Cannot reject offer in status: {$offer->status}.");
        }

        $offer->update([
            'status' => 'rejected',
            'notes'  => $notes ?? $offer->notes,
        ]);

        return $offer->fresh();
    }
}
