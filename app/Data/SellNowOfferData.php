<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\SellNowOffer;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

final class SellNowOfferData extends Data
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $art_lot_id,
        public readonly int     $buyer_id,
        public readonly ?int    $gallery_id,
        public readonly int     $offered_price_cents,
        public readonly ?int    $counter_price_cents,
        public readonly ?int    $agreed_price_cents,
        public readonly string  $currency,
        public readonly string  $status,
        public readonly ?string $notes,
        public readonly ?Carbon $expires_at,
        public readonly ?Carbon $created_at,
    ) {}

    public static function fromOffer(SellNowOffer $offer): self
    {
        return new self(
            id:                   $offer->id,
            art_lot_id:           $offer->art_lot_id,
            buyer_id:             $offer->buyer_id,
            gallery_id:           $offer->gallery_id,
            offered_price_cents:  $offer->offered_price_cents,
            counter_price_cents:  $offer->counter_price_cents,
            agreed_price_cents:   $offer->agreed_price_cents,
            currency:             $offer->currency,
            status:               $offer->status,
            notes:                $offer->notes,
            expires_at:           $offer->expires_at,
            created_at:           $offer->created_at,
        );
    }
}
