<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArtLot;
use App\Models\SellNowOffer;
use App\Models\User;

final class SellNowOfferPolicy
{
    private const STAFF = ['admin', 'operator'];

    /** Counter an offer — consignor (seller) or staff. */
    public function counter(User $user, ArtLot $lot): bool
    {
        return $lot->consignor_id === $user->id
            || in_array($user->role, self::STAFF, true);
    }

    /** Accept or reject an offer — must be a party (buyer or consignor) or staff. */
    public function actOnOffer(User $user, SellNowOffer $offer, ArtLot $lot): bool
    {
        $isParty = $offer->buyer_id === $user->id || $lot->consignor_id === $user->id;

        return $isParty || in_array($user->role, self::STAFF, true);
    }
}
