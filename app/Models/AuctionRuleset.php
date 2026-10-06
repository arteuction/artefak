<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AuctionRuleset extends Model
{
    protected $attributes = [
        'default_bid_increment_cents' => 1000,
        'anti_sniping_seconds'        => 120,
        'extension_seconds'           => 120,
        'proxy_bid_enabled'           => false,
        'reserve_enabled'             => true,
        'counter_offer_enabled'       => false,
        'seller_approval_required'    => false,
        'tie_policy'                  => 'first_wins',
        'max_bid_policy'              => 'disabled',
    ];

    protected $fillable = [
        'name',
        'default_bid_increment_cents',
        'anti_sniping_seconds',
        'extension_seconds',
        'proxy_bid_enabled',
        'reserve_enabled',
        'counter_offer_enabled',
        'seller_approval_required',
        'tie_policy',
        'max_bid_policy',
    ];

    protected function casts(): array
    {
        return [
            'default_bid_increment_cents' => 'integer',
            'anti_sniping_seconds'        => 'integer',
            'extension_seconds'           => 'integer',
            'proxy_bid_enabled'           => 'boolean',
            'reserve_enabled'             => 'boolean',
            'counter_offer_enabled'       => 'boolean',
            'seller_approval_required'    => 'boolean',
        ];
    }

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'ruleset_id');
    }
}
