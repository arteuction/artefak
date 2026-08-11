<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class MetroLine extends Model
{
    protected $fillable = ['code', 'name', 'color'];

    /**
     * Stops ordered by their position on this line.
     */
    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(MetroStop::class, 'metro_stop_lines')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
