<?php

declare(strict_types=1);

namespace App\Domain\Watchlist;

use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Model;

final class Watch
{
    /** Idempotent: no-ops if already watching. */
    public function execute(User $user, Model $subject): WatchlistItem
    {
        return WatchlistItem::firstOrCreate([
            'user_id'      => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id'   => $subject->getKey(),
        ]);
    }
}
