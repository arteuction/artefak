<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConsumerInbox extends Model
{
    protected $table = 'consumer_inbox';

    protected $fillable = [
        'consumer',
        'domain_event_id',
        'event_type',
        'processed_at',
        'result',
    ];

    protected $casts = [
        'domain_event_id' => 'integer',
        'processed_at'    => 'datetime',
    ];

    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }
}
