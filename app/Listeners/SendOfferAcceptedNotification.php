<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\SellNowOffer;
use App\Models\User;
use App\Notifications\OfferAcceptedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Dispatched by the domain event consumer when it processes 'offer.accepted'.
 *
 * This listener is wired to the synthetic Laravel event OfferAccepted,
 * which is fired by the domain event consumer after reading the outbox.
 */
final class SendOfferAcceptedNotification implements ShouldQueue
{
    public function handle(\App\Events\OfferAccepted $event): void
    {
        $offer = SellNowOffer::with('artLot.artwork')->find($event->offerId);

        if ($offer === null) {
            return;
        }

        $buyer = User::find($offer->buyer_id);
        $buyer?->notify(new OfferAcceptedNotification($offer));
    }
}
