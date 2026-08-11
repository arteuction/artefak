<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtmetroVisit extends Model
{
    public $timestamps = false;

    protected $table = 'artmetro_visits';

    protected $fillable = [
        'artifact_id', 'user_id', 'session_id',
        'time_on_page_seconds', 'video_watched', 'ar_launched', 'bid_clicked',
        'visited_at',
    ];

    protected $casts = [
        'video_watched' => 'boolean',
        'ar_launched'   => 'boolean',
        'bid_clicked'   => 'boolean',
        'visited_at'    => 'datetime',
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
