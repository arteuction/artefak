<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent notification dispatch.
 *
 * Every notification is guarded by a unique idempotency_key stored in
 * notification_records. If the key already exists, the notification is
 * silently skipped. This prevents duplicate emails when a domain event
 * is reprocessed or a webhook arrives more than once.
 *
 * The notification itself must still be idempotent at the business level:
 * a failed email must never roll back a sale or invalidate a bid.
 */
class NotificationService
{
    /**
     * Send a queued notification, guaranteed at-most-once per idempotency key.
     *
     * @param  User         $user     The notifiable.
     * @param  Notification $notification
     * @param  string       $key      Globally unique key, e.g. "auction_won.{item_id}.{user_id}"
     */
    public function sendOnce(User $user, Notification $notification, string $key): bool
    {
        $inserted = DB::table('notification_records')
            ->insertOrIgnore([
                'idempotency_key' => $key,
                'user_id'         => $user->id,
                'type'            => get_class($notification),
                'channel'         => 'mail',
                'status'          => 'queued',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

        if ($inserted === 0) {
            return false; // already queued/sent
        }

        try {
            $user->notify($notification);

            DB::table('notification_records')
                ->where('idempotency_key', $key)
                ->update(['status' => 'sent', 'updated_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            DB::table('notification_records')
                ->where('idempotency_key', $key)
                ->update(['status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => now()]);

            Log::error("Notification failed [{$key}]: " . $e->getMessage());

            return false;
        }
    }
}
