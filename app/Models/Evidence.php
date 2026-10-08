<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Evidence extends Model
{
    public const TYPES = [
        'authenticity',
        'provenance',
        'condition',
        'payment',
        'donation',
        'ownership',
        'delivery',
        'impact',
    ];

    protected $table = 'evidence';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'type',
        'subtype',
        'document_path',
        'document_mime',
        'issuer',
        'issued_at',
        'verification_status',
        'verified_at',
        'verified_by',
        'impact_project_id',
        'notes',
    ];

    protected $casts = [
        'subject_id'   => 'integer',
        'issued_at'    => 'date',
        'verified_at'  => 'datetime',
        'verified_by'  => 'integer',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function impactProject(): BelongsTo
    {
        return $this->belongsTo(ImpactProject::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isPending(): bool
    {
        return $this->verification_status === 'pending';
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
