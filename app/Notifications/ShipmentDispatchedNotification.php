<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShipmentDispatchedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string  $artworkTitle,
        public readonly string  $carrier,
        public readonly string  $trackingNumber,
        public readonly ?string $trackingUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject("Your artwork has been dispatched — {$this->artworkTitle}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->artworkTitle}** has been dispatched.")
            ->line("Carrier: **{$this->carrier}**")
            ->line("Tracking number: **{$this->trackingNumber}**");

        if ($this->trackingUrl !== null) {
            $message->action('Track Shipment', $this->trackingUrl);
        }

        return $message;
    }
}
