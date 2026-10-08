<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;
use App\Models\ArtworkRevision;
use App\Models\Consignment;
use App\Models\User;

/**
 * Consignment Workspace — Create Lot from an approved consignment.
 *
 * The lot inherits from the consignment:
 *   - artwork_id (the work being sold)
 *   - gallery_id (the selling venue)
 *   - consignment_id (the legal agreement)
 *   - consignor_id (the artist / owner acting as consignor)
 *   - current artwork revision (the exact metadata state at listing time)
 *   - split_profile_key (defaults to social_pilot_45_45_10)
 *   - currency (from consignment's artwork, defaults EUR)
 *
 * Caller supplies: sale_mode, starting_bid_cents, reserve_price_cents,
 * buy_now_price_cents (for hybrid/sell_now), and the creating user.
 *
 * Consignment must be 'active' to create a lot.
 */
final class CreateLotFromConsignment
{
    public function execute(
        Consignment $consignment,
        User        $createdBy,
        string      $saleMode         = 'auction',
        int         $startingBidCents = 0,
        ?int        $reservePriceCents = null,
        ?int        $buyNowPriceCents  = null,
        string      $splitProfileKey  = 'social_pilot_45_45_10',
        string      $currency         = 'EUR',
    ): ArtLot {
        if ($consignment->status !== 'active') {
            throw new \DomainException(
                "Consignment #{$consignment->id} must be active to create a lot (status: {$consignment->status})."
            );
        }

        $validModes = ['auction', 'sell_now', 'gallery', 'private', 'hybrid'];
        if (! in_array($saleMode, $validModes, true)) {
            throw new \InvalidArgumentException("Unknown sale_mode '{$saleMode}'.");
        }

        if ($saleMode === 'hybrid' && $buyNowPriceCents === null) {
            throw new \InvalidArgumentException('Hybrid sale requires buy_now_price_cents.');
        }

        // Snapshot the current revision if one exists
        $revision = ArtworkRevision::where('artwork_id', $consignment->artwork_id)
            ->where('status', 'active')
            ->first();

        $lot = ArtLot::create([
            'artwork_id'          => $consignment->artwork_id,
            'consignor_id'        => $consignment->consignor_id,
            'gallery_id'          => $consignment->gallery_id,
            'consignment_id'      => $consignment->id,
            'artwork_revision_id' => $revision?->id,
            'sale_mode'           => $saleMode,
            'status'              => 'active',
            'starting_bid_cents'  => $startingBidCents,
            'reserve_price_cents' => $reservePriceCents,
            'buy_now_price_cents' => $buyNowPriceCents,
            'split_profile_key'   => $splitProfileKey,
            'currency'            => $currency,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate:      $lot,
            eventType:      'art_lot.created_from_consignment',
            payload:        [
                'consignment_id' => $consignment->id,
                'sale_mode'      => $saleMode,
                'created_by'     => $createdBy->id,
                'gallery_id'     => $consignment->gallery_id,
            ],
            idempotencyKey: "art_lot.from_consignment:{$consignment->id}:{$saleMode}",
        );

        return $lot;
    }
}
