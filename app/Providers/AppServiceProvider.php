<?php

namespace App\Providers;

use App\Models\ArtistApplication;
use App\Models\ArtworkSdgClaim;
use App\Models\BookCategory;
use App\Models\BookReview;
use App\Models\DomainEvent;
use App\Policies\ArtistApplicationPolicy;
use App\Policies\ArtworkSdgClaimPolicy;
use App\Policies\BookCategoryPolicy;
use App\Policies\BookReviewPolicy;
use App\Policies\DomainEventPolicy;
use Illuminate\Support\Facades\Gate;
use App\Observability\TraceContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped = new instance per Octane request; singleton would leak traceId across requests
        $this->app->scoped(TraceContext::class);
    }

    public function boot(): void
    {
        Gate::policy(ArtistApplication::class, ArtistApplicationPolicy::class);
        Gate::policy(ArtworkSdgClaim::class, ArtworkSdgClaimPolicy::class);
        Gate::policy(BookCategory::class, BookCategoryPolicy::class);
        Gate::policy(BookReview::class, BookReviewPolicy::class);
        Gate::policy(DomainEvent::class, DomainEventPolicy::class);

        Gate::define('admin', fn ($user) => $user->role === 'admin');
        Gate::define('viewPulse', fn ($user) => $user->role === 'admin');
    }
}
