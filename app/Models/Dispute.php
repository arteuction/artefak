<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Dispute extends Model
{
    public const TYPES = [
        'condition_mismatch',
        'damage_after_delivery',
        'unauthorized_sale',
        'title_dispute',
        'payment_dispute',
        'other',
    ];

    public const STATUSES = [
        'open',
        'under_review',
        'resolved',
        'dismissed',
        'escalated',
    ];

    protected $fillable = [
        'type',
        'description',
        'opened_by',
        'art_lot_id',
        'auction_item_id',
        'sell_now_offer_id',
        'ownership_transfer_id',
        'assigned_to',
        'status',
        'resolution',
        'resolved_at',
        'resolved_by',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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

    public function ownershipTransfer(): BelongsTo
    {
        return $this->belongsTo(OwnershipTransfer::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'dismissed'], true);
    }
}
