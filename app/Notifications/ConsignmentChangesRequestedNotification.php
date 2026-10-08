<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Consignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ConsignmentChangesRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Consignment $consignment,
        public readonly string      $reason,
        public readonly string      $requestedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $artwork = $this->consignment->artwork;
        $gallery = $this->consignment->gallery;

        return (new MailMessage())
            ->subject("Changes requested on your consignment — {$artwork?->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$gallery?->name}** has reviewed your consignment for **{$artwork?->title}** and is requesting changes.")
            ->line("**Reason:** {$this->reason}")
            ->line('Please update your consignment and resubmit for review.')
            ->action('View Consignment', url("/consignments/{$this->consignment->id}"));
    }
}
