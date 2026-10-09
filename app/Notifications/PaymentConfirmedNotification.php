<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string  $subject,
        public readonly int     $amountCents,
        public readonly string  $currency,
        public readonly string  $reference,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount   = number_format($this->amountCents / 100, 2);
        $currency = strtoupper($this->currency);

        $message = (new MailMessage())
            ->subject("Payment confirmed — {$this->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your payment of **{$amount} {$currency}** for **{$this->subject}** has been confirmed.")
            ->line("Reference: {$this->reference}");

        if ($this->actionUrl !== null) {
            $message->action($this->actionLabel ?? 'View Details', $this->actionUrl);
        }

        return $message;
    }
}
