<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SellNowOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OfferAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SellNowOffer $offer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lot      = $this->offer->artLot;
        $artwork  = $lot?->artwork;
        $price    = number_format($this->offer->agreed_price_cents / 100, 2);
        $currency = strtoupper($this->offer->currency ?? 'EUR');

        return (new MailMessage())
            ->subject("Your offer was accepted — {$artwork?->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your offer for **{$artwork?->title}** has been accepted.")
            ->line("Agreed price: **{$price} {$currency}**")
            ->line('The gallery will contact you with payment and delivery instructions.')
            ->action('View Offer', url("/art-lots/{$lot?->id}"));
    }
}
