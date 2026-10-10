<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Impact\ImpactMetric;
use App\Domain\Impact\SdgGoal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImpactEvent extends Model
{
    protected $fillable = [
        'artwork_sdg_claim_id',
        'sdg_number',
        'metric',
        'magnitude',
        'currency',
        'type',
        'art_lot_id',
        'donation_id',
        'auction_item_id',
        'sell_now_offer_id',
        'reverses_impact_event_id',
        'idempotency_key',
        'note',
    ];

    protected $casts = [
        'sdg_number' => 'integer',
        'magnitude'  => 'integer',
    ];

    public function sdgClaim(): BelongsTo
    {
        return $this->belongsTo(ArtworkSdgClaim::class, 'artwork_sdg_claim_id');
    }

    public function artLot(): BelongsTo
    {
        return $this->belongsTo(ArtLot::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function sellNowOffer(): BelongsTo
    {
        return $this->belongsTo(SellNowOffer::class);
    }

    public function reversesEvent(): BelongsTo
    {
        return $this->belongsTo(ImpactEvent::class, 'reverses_impact_event_id');
    }

    public function evidence(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ImpactEvidence::class);
    }

    public function sdgGoal(): SdgGoal
    {
        return SdgGoal::from($this->sdg_number);
    }

    public function impactMetric(): ImpactMetric
    {
        return ImpactMetric::from($this->metric);
    }

    public function isReversal(): bool
    {
        return $this->type === 'reversal';
    }
}
