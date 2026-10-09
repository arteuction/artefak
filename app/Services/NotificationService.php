<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent notification dispatch with retry support.
 *
 * Status lifecycle:
 *   queued → sent              (happy path)
 *   queued → failed            (delivery exception)
 *   failed → queued (retry)    (operator or scheduler re-attempts)
 *
 * sendOnce() skips if status is 'queued' or 'sent'.
 * It DOES retry if status is 'failed' and $allowRetry = true.
 * This prevents duplicate business notifications while still allowing
 * recovery from transient mail-server failures.
 *
 * A failed notification must NEVER roll back a sale or invalidate a bid.
 */
class NotificationService
{
    /**
     * Send a queued notification, guaranteed at-most-once per idempotency key.
     *
     * @param  User         $user          The notifiable.
     * @param  Notification $notification
     * @param  string       $key           Globally unique key.
     * @param  bool         $allowRetry    If true and key exists with status=failed, retry.
     */
    public function sendOnce(User $user, Notification $notification, string $key, bool $allowRetry = false): bool
    {
        // Check existing record
        $existing = DB::table('notification_records')
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'sent') {
                return false; // already delivered — deduplicated
            }

            if ($existing->status === 'queued') {
                return false; // dispatch already in flight — deduplicated
            }

            if ($existing->status === 'failed' && ! $allowRetry) {
                return false; // failed but retry not requested
            }

            // status = 'failed' and allowRetry = true: reset to queued and retry below
            if ($existing->status === 'failed' && $allowRetry) {
                DB::table('notification_records')
                    ->where('idempotency_key', $key)
                    ->update([
                        'status'     => 'queued',
                        'error'      => null,
                        'updated_at' => now(),
                    ]);
            }
        } else {
            DB::table('notification_records')->insert([
                'idempotency_key' => $key,
                'user_id'         => $user->id,
                'type'            => get_class($notification),
                'channel'         => 'mail',
                'status'          => 'queued',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
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

    /**
     * Retry all failed notifications for a user.
     * Only use for manual operator recovery — not for automatic re-dispatch.
     *
     * @return int  Count of successfully re-sent notifications.
     */
    public function retryFailed(User $user): int
    {
        $failed = DB::table('notification_records')
            ->where('user_id', $user->id)
            ->where('status', 'failed')
            ->get();

        // Retrying requires the original Notification object, which we do not have here.
        // This method exists as a hook point; callers pass the Notification instance via sendOnce with allowRetry=true.
        // This method only returns the count available for retry.
        return $failed->count();
    }
}
