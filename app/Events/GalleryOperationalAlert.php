<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Operational alert for a specific gallery's staff.
 *
 * Channel: private gallery.{galleryId}
 * Access: active gallery staff or admin.
 *
 * Examples: new consignment submission, SDG claim requires review,
 * artwork verification blocked.
 */
final class GalleryOperationalAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $galleryId,
        public readonly string $type,
        public readonly string $message,
        public readonly array  $context = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("gallery.{$this->galleryId}")];
    }

    public function broadcastAs(): string
    {
        return 'gallery.alert';
    }
}
