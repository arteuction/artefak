<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GalleryStaff extends Model
{
    public const ROLES = ['owner', 'finance', 'curator', 'sales'];

    public const STATUSES = ['invited', 'active', 'revoked'];

    protected $table = 'gallery_staff';

    protected $fillable = [
        'gallery_id',
        'user_id',
        'role',
        'status',
        'invited_at',
        'accepted_at',
        'invited_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'invited_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
