<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DonationRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Donation $donation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount    = number_format($this->donation->donated_cents / 100, 2);
        $currency  = strtoupper($this->donation->currency);
        $recipient = $this->donation->recipient?->name ?? 'the designated recipient';

        return (new MailMessage())
            ->subject("Donation confirmed — {$amount} {$currency} to {$recipient}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your donation of **{$amount} {$currency}** to **{$recipient}** has been recorded.")
            ->line("You may be eligible for a tax deduction under ЗКПО чл. 31. A tax documentation summary is available in your account.")
            ->action('View Donation', url("/donations/{$this->donation->id}"));
    }
}
