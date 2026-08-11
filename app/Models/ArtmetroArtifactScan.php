<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtmetroArtifactScan extends Model
{
    public $timestamps = false;

    protected $table = 'artmetro_artifact_scans';

    protected $fillable = [
        'artifact_id', 'user_id',
        'referrer', 'campaign', 'locale',
        'ip_address', 'user_agent',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ArtmetroArtifact::class, 'artifact_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
