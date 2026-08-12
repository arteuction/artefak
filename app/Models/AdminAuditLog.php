<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class AdminAuditLog extends Model
{
    // Immutable — no updates ever
    public const UPDATED_AT = null;

    protected $table = 'admin_audit_log';

    protected $fillable = [
        'actor_id', 'subject_type', 'subject_id', 'action', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Write an immutable audit entry. Never call update() on this model.
     */
    public static function record(int $actorId, Model $subject, string $action, array $payload = []): self
    {
        return self::create([
            'actor_id'     => $actorId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id'   => $subject->getKey(),
            'action'       => $action,
            'payload'      => empty($payload) ? null : $payload,
        ]);
    }
}
