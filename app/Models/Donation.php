<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Donation extends Model
{
    protected $fillable = [
        'donation_recipient_id',
        'donor_id',
        'art_lot_id',
        'auction_item_id',
        'sell_now_offer_id',
        'donated_cents',
        'currency',
        'eligibility_basis',
        'deduction_bps',
        'max_deductible_cents',
        'type',
        'status',
        'reverses_donation_id',
        'idempotency_key',
        'impact_project_id',
    ];

    protected $casts = [
        'donated_cents'         => 'integer',
        'deduction_bps'         => 'integer',
        'max_deductible_cents'  => 'integer',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(DonationRecipient::class, 'donation_recipient_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function artLot(): BelongsTo
    {
        return $this->belongsTo(ArtLot::class);
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function sellNowOffer(): BelongsTo
    {
        return $this->belongsTo(SellNowOffer::class);
    }

    public function reversesDonation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'reverses_donation_id');
    }

    public function impactProject(): BelongsTo
    {
        return $this->belongsTo(ImpactProject::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
