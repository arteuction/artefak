<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Synthetic event dispatched by the domain event consumer after 'offer.accepted'. */
final class OfferAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly int $offerId,
    ) {}
}
