<?php

namespace App\Providers;

use App\Models\ArtistApplication;
use App\Models\ArtworkSdgClaim;
use App\Models\DomainEvent;
use App\Policies\ArtistApplicationPolicy;
use App\Policies\ArtworkSdgClaimPolicy;
use App\Policies\DomainEventPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(ArtistApplication::class, ArtistApplicationPolicy::class);
        Gate::policy(ArtworkSdgClaim::class, ArtworkSdgClaimPolicy::class);
        Gate::policy(DomainEvent::class, DomainEventPolicy::class);

        Gate::define('admin', fn ($user) => $user->role === 'admin');
    }
}
