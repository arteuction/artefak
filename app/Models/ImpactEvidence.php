<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ImpactEvidence extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'impact_event_id',
        'uploaded_by',
        'type',
        'url',
        'description',
    ];

    public function impactEvent(): BelongsTo
    {
        return $this->belongsTo(ImpactEvent::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
