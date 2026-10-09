<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the private admin channel so on-call staff can see alerts
 * in real time: failed transfers, stuck domain events, reconciliation gaps.
 */
final class AdminOperationalAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $alertType,
        public readonly string $message,
        public readonly array  $context = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('admin');
    }

    public function broadcastAs(): string
    {
        return 'admin.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'alert_type' => $this->alertType,
            'message'    => $this->message,
            'context'    => $this->context,
            'fired_at'   => now()->toIso8601String(),
        ];
    }
}
