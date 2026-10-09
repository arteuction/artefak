<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuctionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AuctionWonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AuctionItem $item,
        public readonly int         $hammerPriceCents,
        public readonly string      $currency = 'BGN',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $artwork  = $this->item->artLot?->artwork;
        $price    = number_format($this->hammerPriceCents / 100, 2);
        $currency = strtoupper($this->currency);

        return (new MailMessage())
            ->subject("Congratulations — you won the auction for {$artwork?->title}")
            ->greeting("Congratulations {$notifiable->name},")
            ->line("You have won the auction for **{$artwork?->title}**.")
            ->line("Hammer price: **{$price} {$currency}**")
            ->line("Please complete your payment within the required timeframe to secure your artwork.")
            ->action('Complete Payment', url("/auction-items/{$this->item->id}/payment"));
    }
}
