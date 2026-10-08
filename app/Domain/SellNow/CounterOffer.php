<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Models\SellNowOffer;
use InvalidArgumentException;

final class CounterOffer
{
    public function execute(
        SellNowOffer $offer,
        int $counterPriceCents,
        ?string $notes = null,
    ): SellNowOffer {
        if ($offer->status !== 'submitted') {
            throw new InvalidArgumentException("Can only counter a submitted offer (status: {$offer->status}).");
        }

        if ($counterPriceCents <= 0) {
            throw new InvalidArgumentException('Counter price must be positive.');
        }

        $offer->update([
            'counter_price_cents' => $counterPriceCents,
            'status'              => 'countered',
            'notes'               => $notes ?? $offer->notes,
        ]);

        return $offer->fresh();
    }
}
