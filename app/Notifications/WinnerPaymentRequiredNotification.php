<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuctionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WinnerPaymentRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AuctionItem $item,
        public readonly int         $amountCents,
        public readonly string      $currency,
        public readonly \Carbon\Carbon $deadline,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $artwork  = $this->item->artLot?->artwork;
        $amount   = number_format($this->amountCents / 100, 2);
        $currency = strtoupper($this->currency);
        $deadline = $this->deadline->format('d M Y H:i');

        return (new MailMessage())
            ->subject("Payment required by {$deadline} — {$artwork?->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Payment of **{$amount} {$currency}** for **{$artwork?->title}** is due by **{$deadline}**.")
            ->line("Failure to pay by this deadline may result in forfeiture of the lot.")
            ->action('Pay Now', url("/auction-items/{$this->item->id}/payment"));
    }
}
