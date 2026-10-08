<?php

declare(strict_types=1);

namespace App\Domain\Watchlist;

use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Model;

final class Unwatch
{
    /** Idempotent: no-ops if not watching. */
    public function execute(User $user, Model $subject): void
    {
        WatchlistItem::where([
            'user_id'      => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id'   => $subject->getKey(),
        ])->delete();
    }
}
