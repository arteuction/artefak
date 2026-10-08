<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\User;
use InvalidArgumentException;

final class CreateConsignment
{
    public function execute(
        Artwork $artwork,
        User    $owner,
        User    $consignor,
        int     $commissionBps = 0,
        ?Gallery $gallery = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $endsAt   = null,
        ?string $notes = null,
    ): Consignment {
        if ($commissionBps < 0 || $commissionBps > 10000) {
            throw new InvalidArgumentException('commission_bps must be 0–10000.');
        }

        if ($endsAt !== null && $startsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('ends_at must be after starts_at.');
        }

        return Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $consignor->id,
            'gallery_id'     => $gallery?->id,
            'commission_bps' => $commissionBps,
            'status'         => 'draft',
            'starts_at'      => $startsAt,
            'ends_at'        => $endsAt,
            'notes'          => $notes,
        ]);
    }
}
