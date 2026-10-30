<?php

namespace App\Providers;

use App\Models\ArtistApplication;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookReview;
use App\Models\Dispute;
use App\Models\DomainEvent;
use App\Models\Gallery;
use App\Models\SellNowOffer;
use App\Models\User;
use App\Policies\AdminPolicy;
use App\Policies\ArtistApplicationPolicy;
use App\Policies\ArtLotPolicy;
use App\Policies\ArtworkPolicy;
use App\Policies\ArtworkSdgClaimPolicy;
use App\Policies\BookCategoryPolicy;
use App\Policies\BookPolicy;
use App\Policies\BookReviewPolicy;
use App\Policies\DisputePolicy;
use App\Policies\DomainEventPolicy;
use App\Policies\GalleryPolicy;
use App\Policies\SellNowOfferPolicy;
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
        // Model policies
        Gate::policy(ArtistApplication::class, ArtistApplicationPolicy::class);
        Gate::policy(ArtLot::class, ArtLotPolicy::class);
        Gate::policy(Artwork::class, ArtworkPolicy::class);
        Gate::policy(ArtworkSdgClaim::class, ArtworkSdgClaimPolicy::class);
        Gate::policy(Book::class, BookPolicy::class);
        Gate::policy(BookCategory::class, BookCategoryPolicy::class);
        Gate::policy(BookReview::class, BookReviewPolicy::class);
        Gate::policy(Dispute::class, DisputePolicy::class);
        Gate::policy(DomainEvent::class, DomainEventPolicy::class);
        Gate::policy(Gallery::class, GalleryPolicy::class);
        Gate::policy(SellNowOffer::class, SellNowOfferPolicy::class);

        // Gate abilities (no single model)
        Gate::define('admin', fn (User $user) => $user->role === 'admin');
        Gate::define('viewPulse', fn (User $user) => $user->role === 'admin');
        Gate::define('admin-area', [AdminPolicy::class, 'adminArea']);
    }
}
