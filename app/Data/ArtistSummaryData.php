<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;

final class ArtistSummaryData extends Data
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(id: $user->id, name: $user->name);
    }
}
