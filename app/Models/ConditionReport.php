<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConditionReport extends Model
{
    protected $fillable = [
        'artwork_id', 'inspector_id', 'version',
        'surface', 'frame', 'signature_status',
        'certificate_available', 'provenance_status', 'restoration',
        'notes', 'status', 'valid_at',
    ];

    protected function casts(): array
    {
        return [
            'certificate_available' => 'boolean',
            'valid_at'              => 'datetime',
            'version'               => 'integer',
        ];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
