<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;

final class ArtistSummaryData extends Data
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly ?string $profile_slug,
    ) {}

    public static function fromUser(User $user): self
    {
        $slug = $user->relationLoaded('artistProfile') ? $user->artistProfile?->slug : null;
        return new self(id: $user->id, name: $user->name, profile_slug: $slug);
    }
}
