<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Bid;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

final class BidData extends Data
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $auction_item_id,
        public readonly int     $user_id,
        public readonly int     $amount_cents,
        public readonly string  $status,
        public readonly ?string $payment_status,
        public readonly ?Carbon $authorization_expires_at,
        public readonly ?Carbon $created_at,
    ) {}

    public static function fromBid(Bid $bid): self
    {
        return new self(
            id:                       $bid->id,
            auction_item_id:          $bid->auction_item_id,
            user_id:                  $bid->user_id,
            amount_cents:             $bid->amount_cents,
            status:                   $bid->status,
            payment_status:           $bid->payment_status ?? null,
            authorization_expires_at: $bid->authorization_expires_at,
            created_at:               $bid->created_at,
        );
    }
}
