<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

final class UserProfileData extends Data
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $email,
        public readonly ?string $role,
        public readonly ?Carbon $created_at,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            id:         $user->id,
            name:       $user->name,
            email:      $user->email,
            role:       $user->role ?? null,
            created_at: $user->created_at,
        );
    }
}
