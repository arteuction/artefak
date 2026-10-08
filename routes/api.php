<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\ArtmetroRouteController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\BidController;
use App\Http\Controllers\Api\V1\ArtLotController;
use App\Http\Controllers\Api\V1\ArtworkController;
use App\Http\Controllers\Api\V1\AuctionController as V1AuctionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ConsignmentController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\DomainEventController;
use App\Http\Controllers\Api\V1\ExhibitionController;
use App\Http\Controllers\Api\V1\GalleryController;
use App\Http\Controllers\Api\V1\GalleryStaffController;
use App\Http\Controllers\Api\V1\SdgClaimController;
use App\Http\Controllers\Api\V1\ImpactEventController;
use App\Http\Controllers\Api\V1\ImpactProjectController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OwnershipTransferController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\SellNowOfferController;
use App\Http\Controllers\Api\V1\VenueController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api, middleware: api)
|--------------------------------------------------------------------------
*/

// Public — auction discovery
Route::get('/auctions', [AuctionController::class, 'index']);
Route::get('/auctions/{auction}', [AuctionController::class, 'show']);
Route::get('/auctions/{auction}/items/{item}', [AuctionController::class, 'item']);

// Authenticated — bidding (rate-limited: 30/min per user)
Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function (): void {
    Route::post('/auctions/{auction}/items/{item}/bids', [BidController::class, 'store']);
});

// ArtMetro — QR artifact info (read-only, safe for crawlers/prefetch)
Route::get('/artifacts/{qrToken}', [ArtifactController::class, 'show']);

// ArtMetro — QR scan record (mutating, rate-limited: 30/min per IP)
Route::middleware('throttle:30,1')->group(function (): void {
    Route::post('/artifacts/{qrToken}/scans', [ArtifactController::class, 'recordScan']);
});

// ArtMetro — link/unlink an ArtLot to a physical label (gallery staff, authenticated)
Route::middleware('auth:sanctum')->group(function (): void {
    Route::patch('/artifacts/{artifact}/lot', [ArtifactController::class, 'linkLot']);
});

// ArtMetro — visit beacon (mutating, rate-limited: 60/min per IP)
Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('/artifacts/{artifact}/visit', [ArtifactController::class, 'visit']);
});

// ArtMetro — routes (public)
Route::get('/routes', [ArtmetroRouteController::class, 'index']);
Route::get('/routes/{route}', [ArtmetroRouteController::class, 'show']);

/*
|--------------------------------------------------------------------------
| API v1  (prefix: /api/v1)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->name('v1.')->group(function (): void {

    // Auth (public, rate-limited: 10 attempts per minute per IP)
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login',    [AuthController::class, 'login'])->name('login');
    });

    // Artworks
    Route::get('/artworks',        [ArtworkController::class, 'index'])->name('artworks.index');
    Route::get('/artworks/{artwork}', [ArtworkController::class, 'show'])->name('artworks.show');

    // Artwork sales history (public — privacy-preserving, no buyer identity)
    Route::get('/artworks/{artwork}/sales-history', [PayoutController::class, 'artworkSalesHistory'])->name('artworks.sales-history');

    // ArtLots
    Route::get('/art-lots',                    [ArtLotController::class, 'index'])->name('art-lots.index');
    Route::get('/art-lots/{artLot}',           [ArtLotController::class, 'show'])->name('art-lots.show');
    Route::get('/art-lots/{artLot}/bids',      [ArtLotController::class, 'bids'])->name('art-lots.bids');

    // Galleries (public read)
    Route::get('/galleries',                    [GalleryController::class, 'index'])->name('galleries.index');
    Route::get('/galleries/{gallery}',          [GalleryController::class, 'show'])->name('galleries.show');
    Route::get('/galleries/{gallery}/profile',  [GalleryController::class, 'profile'])->name('galleries.profile');
    Route::get('/galleries/{gallery}/staff',    [GalleryStaffController::class, 'index'])->name('galleries.staff.index');

    // SDG claims (public read — approved only)
    Route::get('/artworks/{artwork}/sdg-claims', [SdgClaimController::class, 'index'])->name('artworks.sdg-claims.index');

    // Venues (public read)
    Route::get('/venues',         [VenueController::class, 'index'])->name('venues.index');
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->name('venues.show');

    // Exhibitions (public read)
    Route::get('/exhibitions',                [ExhibitionController::class, 'index'])->name('exhibitions.index');
    Route::get('/exhibitions/{exhibition}',   [ExhibitionController::class, 'show'])->name('exhibitions.show');

    // Impact events (public read)
    Route::get('/impact-events',                  [ImpactEventController::class, 'index'])->name('impact-events.index');
    Route::get('/impact-events/{impactEvent}',    [ImpactEventController::class, 'show'])->name('impact-events.show');

    // Impact projects (public read + public stats)
    Route::get('/impact-projects/stats',              [ImpactProjectController::class, 'stats'])->name('impact-projects.stats');
    Route::get('/impact-projects',                    [ImpactProjectController::class, 'index'])->name('impact-projects.index');
    Route::get('/impact-projects/{impactProject}',    [ImpactProjectController::class, 'show'])->name('impact-projects.show');
    Route::get('/impact-projects/{impactProject}/evidence', [ImpactProjectController::class, 'indexEvidence'])->name('impact-projects.evidence.index');


    // Collections (public show for unlisted/public; authenticated for index/store/manage)
    Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');

    // Auctions (public read)
    Route::get('/auctions',                              [V1AuctionController::class, 'index'])->name('auctions.index');
    Route::get('/auctions/{auction}',                    [V1AuctionController::class, 'show'])->name('auctions.show');
    Route::get('/auctions/{auction}/items/{item}',       [V1AuctionController::class, 'item'])->name('auctions.item');

    // Authenticated mutations
    Route::middleware('auth:sanctum')->group(function (): void {

        // Artworks
        Route::post('/artworks', [ArtworkController::class, 'store'])->name('artworks.store');
        Route::post('/artworks/{artwork}/revisions', [ArtworkController::class, 'storeRevision'])->name('artworks.revisions.store');
        Route::post('/artworks/{artwork}/revisions/{revision}/activate', [ArtworkController::class, 'activateRevision'])->name('artworks.revisions.activate');

        // ArtLots
        Route::post('/art-lots', [ArtLotController::class, 'store'])->name('art-lots.store');
        Route::post('/art-lots/{artLot}/purchase-now', [ArtLotController::class, 'purchaseNow'])->name('art-lots.purchase-now');
        Route::post('/art-lots/{artLot}/transitions/{transition}', [ArtLotController::class, 'transition'])->name('art-lots.transition');

        // Sell Now offers
        Route::get('/art-lots/{artLot}/sell-now-offers',  [SellNowOfferController::class, 'index'])
             ->name('sell-now-offers.index');
        Route::post('/art-lots/{artLot}/sell-now-offers', [SellNowOfferController::class, 'store'])
             ->name('sell-now-offers.store');
        Route::post('/sell-now-offers/{offer}/counter',   [SellNowOfferController::class, 'counter'])
             ->name('sell-now-offers.counter');
        Route::post('/sell-now-offers/{offer}/accept',    [SellNowOfferController::class, 'accept'])
             ->name('sell-now-offers.accept');
        Route::post('/sell-now-offers/{offer}/reject',    [SellNowOfferController::class, 'reject'])
             ->name('sell-now-offers.reject');

        // Consignments
        Route::get('/consignments',                         [ConsignmentController::class, 'index'])->name('consignments.index');
        Route::get('/consignments/{consignment}',           [ConsignmentController::class, 'show'])->name('consignments.show');
        Route::post('/consignments',                        [ConsignmentController::class, 'store'])->name('consignments.store');
        Route::post('/consignments/{consignment}/activate',       [ConsignmentController::class, 'activate'])->name('consignments.activate');
        Route::post('/consignments/{consignment}/approve',        [ConsignmentController::class, 'approve'])->name('consignments.approve');
        Route::post('/consignments/{consignment}/request-changes',[ConsignmentController::class, 'requestChanges'])->name('consignments.request-changes');
        Route::post('/consignments/{consignment}/create-lot',     [ConsignmentController::class, 'createLot'])->name('consignments.create-lot');

        // Gallery staff management (owner-only mutations; read is public above)
        Route::post('/galleries/{gallery}/staff',          [GalleryStaffController::class, 'store'])->name('galleries.staff.store');
        Route::delete('/galleries/{gallery}/staff/{user}', [GalleryStaffController::class, 'destroy'])->name('galleries.staff.destroy');

        // Collections
        Route::get('/collections',                                          [CollectionController::class, 'index'])->name('collections.index');
        Route::post('/collections',                                         [CollectionController::class, 'store'])->name('collections.store');
        Route::post('/collections/{collection}/artworks',                   [CollectionController::class, 'addArtwork'])->name('collections.artworks.store');
        Route::delete('/collections/{collection}/artworks/{artwork}',       [CollectionController::class, 'removeArtwork'])->name('collections.artworks.destroy');

        // Watchlist
        Route::get('/watchlist',                      [WatchlistController::class, 'index'])->name('watchlist.index');
        Route::post('/watchlist',                     [WatchlistController::class, 'store'])->name('watchlist.store');
        Route::delete('/watchlist/{type}/{id}',       [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

        // Auth — logout
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Me (current user profile)
        Route::get('/me', [ProfileController::class, 'show'])->name('me');

        // Donations (donor sees own; admin sees all)
        Route::get('/donations',                      [DonationController::class, 'index'])->name('donations.index');
        Route::get('/donations/tax-receipt/{year?}',  [DonationController::class, 'taxReceipt'])->name('donations.tax-receipt');
        Route::get('/donations/{donation}',           [DonationController::class, 'show'])->name('donations.show');
        Route::post('/donations',                     [DonationController::class, 'store'])->name('donations.store');

        // Impact projects — create/update/evidence (admin/operator)
        Route::post('/impact-projects',                                    [ImpactProjectController::class, 'store'])->name('impact-projects.store');
        Route::patch('/impact-projects/{impactProject}',                   [ImpactProjectController::class, 'update'])->name('impact-projects.update');
        Route::post('/impact-projects/{impactProject}/evidence',           [ImpactProjectController::class, 'storeEvidence'])->name('impact-projects.evidence.store');

        // SDG claims — submit (artwork owner) + review (admin/operator)
        Route::post('/artworks/{artwork}/sdg-claims',                           [SdgClaimController::class, 'store'])->name('artworks.sdg-claims.store');
        Route::post('/artworks/{artwork}/sdg-claims/{claim}/review',            [SdgClaimController::class, 'review'])->name('artworks.sdg-claims.review');

        // Ownership transfers (party to transfer sees own; admin sees all)
        Route::get('/ownership-transfers',                       [OwnershipTransferController::class, 'index'])->name('ownership-transfers.index');
        Route::get('/ownership-transfers/{ownershipTransfer}',   [OwnershipTransferController::class, 'show'])->name('ownership-transfers.show');

        // Domain events — operator only
        Route::middleware('can:viewAny,App\Models\DomainEvent')->group(function (): void {
            Route::get('/domain-events', [DomainEventController::class, 'index'])->name('domain-events.index');
        });

        // Payout dashboard — artist sees own lines; gallery finance/owner sees gallery
        Route::get('/payouts/artist',              [PayoutController::class, 'artist'])             ->name('payouts.artist');
        Route::get('/payouts/gallery/{gallery}',   [PayoutController::class, 'gallery'])            ->name('payouts.gallery');

        // Operations console — admin only
        Route::middleware('can:admin')->prefix('ops')->name('ops.')->group(function (): void {
            Route::get('/summary',              [OperationsController::class, 'summary'])             ->name('summary');
            Route::get('/pending-events',       [OperationsController::class, 'pendingEvents'])       ->name('pending-events');
            Route::get('/failed-outbox',        [OperationsController::class, 'failedOutbox'])        ->name('failed-outbox');
            Route::get('/open-reserves',        [OperationsController::class, 'openReserves'])        ->name('open-reserves');
            Route::get('/active-consignments',  [OperationsController::class, 'activeConsignments'])  ->name('active-consignments');
            Route::get('/fiscal-year/{year?}',  [OperationsController::class, 'fiscalYearSummaries']) ->name('fiscal-year');
            Route::get('/zkpo-report/{year?}',  [OperationsController::class, 'zkpoReport'])           ->name('zkpo-report');
            Route::get('/consumer-lag',         [OperationsController::class, 'consumerLag'])         ->name('consumer-lag');
        });
    });
});
