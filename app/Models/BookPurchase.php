<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class BookPurchase extends Model
{
    protected $fillable = [
        'buyer_id', 'book_id', 'status',
        'price_cents', 'currency',
        'gross_cents', 'tax_cents', 'fee_cents', 'split_base_cents',
        'profile_key', 'profile_version', 'author_bps', 'fund_bps', 'ops_bps',
        'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'idempotency_key', 'paid_at', 'refunded_cents',
    ];

    protected function casts(): array
    {
        return [
            'price_cents'      => 'integer',
            'gross_cents'      => 'integer',
            'tax_cents'        => 'integer',
            'fee_cents'        => 'integer',
            'split_base_cents' => 'integer',
            'author_bps'       => 'integer',
            'fund_bps'         => 'integer',
            'ops_bps'          => 'integer',
            'refunded_cents'   => 'integer',
            'profile_version'  => 'integer',
            'paid_at'          => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(BookEntitlement::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isRefundable(): bool
    {
        return in_array($this->status, ['paid', 'partially_refunded'], true);
    }

    public function remainingCents(): int
    {
        return $this->gross_cents - $this->refunded_cents;
    }
}
