<?php

namespace App\Providers;

use App\Models\ArtistApplication;
use App\Models\ArtworkSdgClaim;
use App\Policies\ArtistApplicationPolicy;
use App\Policies\ArtworkSdgClaimPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(ArtistApplication::class, ArtistApplicationPolicy::class);
        Gate::policy(ArtworkSdgClaim::class, ArtworkSdgClaimPolicy::class);
    }
}
