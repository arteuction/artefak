<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\AdminOperationalAlert;
use Illuminate\Support\Facades\Log;

/**
 * Fires broadcast alerts to the private admin channel.
 *
 * Callers (jobs, commands, domain actions) use this service to surface
 * anomalies to on-call staff without blocking the originating operation.
 */
final class OperationalAlertService
{
    public function alert(string $type, string $message, array $context = []): void
    {
        try {
            event(new AdminOperationalAlert($type, $message, $context));
        } catch (\Throwable $e) {
            Log::error('OperationalAlertService: failed to broadcast alert', [
                'type'    => $type,
                'message' => $message,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
