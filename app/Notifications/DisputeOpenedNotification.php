<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DisputeOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Dispute $dispute) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Dispute opened — #{$this->dispute->id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A dispute (#{$this->dispute->id}) has been opened regarding your transaction.")
            ->line("Our team will review this and respond within 3 business days.")
            ->action('View Dispute', url("/disputes/{$this->dispute->id}"));
    }
}
