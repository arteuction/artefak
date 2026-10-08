<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DisputeEvidence extends Model
{
    protected $table = 'dispute_evidence';

    protected $fillable = [
        'dispute_id',
        'submitted_by',
        'type',
        'document_path',
        'notes',
    ];

    protected $casts = [
        'dispute_id'   => 'integer',
        'submitted_by' => 'integer',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
