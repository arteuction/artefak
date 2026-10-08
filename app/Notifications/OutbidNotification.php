<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuctionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OutbidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AuctionItem $item,
        public readonly int         $currentBidCents,
        public readonly int         $nextBidCents,
        public readonly string      $currency = 'EUR',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $artwork   = $this->item->artLot?->artwork;
        $current   = number_format($this->currentBidCents / 100, 2);
        $next      = number_format($this->nextBidCents / 100, 2);
        $currency  = strtoupper($this->currency);

        return (new MailMessage())
            ->subject("You've been outbid — {$artwork?->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Someone has placed a higher bid on **{$artwork?->title}**.")
            ->line("Current highest bid: **{$current} {$currency}**")
            ->line("Minimum to retake the lead: **{$next} {$currency}**")
            ->action('Bid Now', url("/auctions/{$this->item->auction_id}/items/{$this->item->id}"));
    }
}
