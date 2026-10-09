<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\ArtmetroRouteController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\BidController;
use App\Http\Controllers\Api\V1\ArtistApplicationController;
use App\Http\Controllers\Api\V1\ArtLotController;
use App\Http\Controllers\Api\V1\EvidenceController;
use App\Http\Controllers\Api\V1\ReserveController;
use App\Http\Controllers\Api\V1\ArtworkController;
use App\Http\Controllers\Api\V1\AuctionController as V1AuctionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ConsignmentController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\DomainEventController;
use App\Http\Controllers\Api\V1\ExhibitionController;
use App\Http\Controllers\Api\V1\FulfillmentController;
use App\Http\Controllers\Api\V1\GalleryController;
use App\Http\Controllers\Api\V1\GalleryStaffController;
use App\Http\Controllers\Api\V1\ImpactRecordController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\SdgClaimController;
use App\Http\Controllers\Api\V1\ImpactEventController;
use App\Http\Controllers\Api\V1\ImpactProjectController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OwnershipTransferController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\SellNowOfferController;
use App\Http\Controllers\Api\V1\VenueController;
use App\Http\Controllers\Api\V1\AdminAuditLogController;
use App\Http\Controllers\Api\V1\DonationRecipientController;
use App\Http\Controllers\Api\V1\ArtmetroAdminController;
use App\Http\Controllers\Api\V1\BookAuthorController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\SplitProfileController;
use App\Http\Controllers\Api\V1\UserAdminController;
use App\Http\Controllers\Api\V1\AdminAuctionController;
use App\Http\Controllers\Api\V1\AdminBookController;
use App\Http\Controllers\Api\V1\AdminPayoutController;
use App\Http\Controllers\Api\V1\AdminConnectedPayoutController;
use App\Http\Controllers\Api\V1\AdminRulesetController;
use App\Http\Controllers\Api\V1\AdminTransferOutboxController;
use App\Http\Controllers\Api\V1\BookFileController;
use App\Http\Controllers\Api\V1\BookFileUploadController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicArtworkController;
use App\Http\Controllers\Api\V1\ArtistPortfolioController;
use App\Http\Controllers\Api\V1\BuyerDashboardController;
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

    // Public artwork search catalog
    Route::get('/search/artworks',           [PublicArtworkController::class, 'index'])->name('search.artworks.index');
    Route::get('/search/artworks/{artwork}', [PublicArtworkController::class, 'show'])->name('search.artworks.show');

    // Public artist portfolios
    Route::get('/artists',        [ArtistPortfolioController::class, 'index'])->name('artists.index');
    Route::get('/artists/{slug}', [ArtistPortfolioController::class, 'show'])->name('artists.show');

    // Artworks
    Route::get('/artworks',        [ArtworkController::class, 'index'])->name('artworks.index');
    Route::get('/artworks/{artwork}', [ArtworkController::class, 'show'])->name('artworks.show');
    Route::get('/artworks/{artwork}/evidence', [ArtworkController::class, 'indexEvidence'])->name('artworks.evidence.index');

    // Artwork sales history (public — privacy-preserving, no buyer identity)
    Route::get('/artworks/{artwork}/sales-history', [PayoutController::class, 'artworkSalesHistory'])->name('artworks.sales-history');

    // ArtLots
    Route::get('/art-lots',                    [ArtLotController::class, 'index'])->name('art-lots.index');
    Route::get('/art-lots/{artLot}',           [ArtLotController::class, 'show'])->name('art-lots.show');
    Route::get('/art-lots/{artLot}/bids',       [ArtLotController::class, 'bids'])->name('art-lots.bids');
    Route::get('/art-lots/{artLot}/provenance', [ArtLotController::class, 'provenance'])->name('art-lots.provenance');

    // Galleries (public read)
    Route::get('/galleries',                    [GalleryController::class, 'index'])->name('galleries.index');
    Route::get('/galleries/{gallery}',          [GalleryController::class, 'show'])->name('galleries.show');
    Route::get('/galleries/{gallery}/profile',  [GalleryController::class, 'profile'])->name('galleries.profile');
    Route::get('/galleries/{gallery}/staff',    [GalleryStaffController::class, 'index'])->name('galleries.staff.index');

    // SDG claims (public read — approved only)
    Route::get('/artworks/{artwork}/sdg-claims',              [SdgClaimController::class, 'index'])->name('artworks.sdg-claims.index');
    Route::get('/artworks/{artwork}/sdg-claims/{claim}',      [SdgClaimController::class, 'show'])->name('artworks.sdg-claims.show');

    // Venues (public read)
    Route::get('/venues',         [VenueController::class, 'index'])->name('venues.index');
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->name('venues.show');

    // Exhibitions (public read)  — store is in the auth group below

    // Exhibitions (public read)
    Route::get('/exhibitions',                [ExhibitionController::class, 'index'])->name('exhibitions.index');
    Route::get('/exhibitions/{exhibition}',   [ExhibitionController::class, 'show'])->name('exhibitions.show');

    // Library — public catalogue
    Route::get('/books',           [LibraryController::class, 'index'])->name('books.index');
    Route::get('/books/{book}',    [LibraryController::class, 'show'])->name('books.show');

    // Impact events (public read)
    Route::get('/impact-events',                  [ImpactEventController::class, 'index'])->name('impact-events.index');
    Route::get('/impact-events/{impactEvent}',    [ImpactEventController::class, 'show'])->name('impact-events.show');

    // Donation recipients (public: active list; admin: all)
    Route::get('/donation-recipients',                [DonationRecipientController::class, 'index'])->name('donation-recipients.index');
    Route::get('/donation-recipients/{donationRecipient}', [DonationRecipientController::class, 'show'])->name('donation-recipients.show');

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
        Route::post('/artworks',              [ArtworkController::class, 'store'])->name('artworks.store');
        Route::patch('/artworks/{artwork}',   [ArtworkController::class, 'update'])->name('artworks.update');
        Route::post('/artworks/{artwork}/revisions', [ArtworkController::class, 'storeRevision'])->name('artworks.revisions.store');
        Route::post('/artworks/{artwork}/revisions/{revision}/activate', [ArtworkController::class, 'activateRevision'])->name('artworks.revisions.activate');
        Route::post('/artworks/{artwork}/evidence',  [ArtworkController::class, 'storeEvidence'])->name('artworks.evidence.store');

        // Venues — create/update (admin)
        Route::post('/venues',          [VenueController::class, 'store'])->name('venues.store');
        Route::patch('/venues/{venue}', [VenueController::class, 'update'])->name('venues.update');

        // Exhibitions — create (gallery staff or admin)
        Route::post('/exhibitions', [ExhibitionController::class, 'store'])->name('exhibitions.store');

        // ArtLots
        Route::post('/art-lots',              [ArtLotController::class, 'store'])->name('art-lots.store');
        Route::patch('/art-lots/{artLot}',    [ArtLotController::class, 'update'])->name('art-lots.update');
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
        Route::patch('/galleries/{gallery}/staff/{staffMember}',  [GalleryStaffController::class, 'update'])->name('galleries.staff.update');
        Route::delete('/galleries/{gallery}/staff/{user}', [GalleryStaffController::class, 'destroy'])->name('galleries.staff.destroy');

        // Collections
        Route::get('/collections',                                          [CollectionController::class, 'index'])->name('collections.index');
        Route::post('/collections',                                         [CollectionController::class, 'store'])->name('collections.store');
        Route::post('/collections/{collection}/artworks',                   [CollectionController::class, 'addArtwork'])->name('collections.artworks.store');
        Route::delete('/collections/{collection}/artworks/{artwork}',       [CollectionController::class, 'removeArtwork'])->name('collections.artworks.destroy');
        Route::patch('/collections/{collection}',                            [CollectionController::class, 'update'])->name('collections.update');
        Route::delete('/collections/{collection}',                           [CollectionController::class, 'destroy'])->name('collections.destroy');

        // Watchlist
        Route::get('/watchlist',                      [WatchlistController::class, 'index'])->name('watchlist.index');
        Route::post('/watchlist',                     [WatchlistController::class, 'store'])->name('watchlist.store');
        Route::delete('/watchlist/{type}/{id}',       [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

        // Buyer dashboard
        Route::get('/my/won-items',                   [BuyerDashboardController::class, 'wonItems'])->name('buyer.won-items');
        Route::get('/my/bids',                        [BuyerDashboardController::class, 'myBids'])->name('buyer.bids');
        Route::get('/auction-items/{item}/bid-history', [BuyerDashboardController::class, 'bidHistory'])->name('auction-items.bid-history');

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

        // Fulfillment
        Route::post('/auction-items/{item}/ship',             [FulfillmentController::class, 'shipAuctionItem'])->name('auction-items.ship');
        Route::post('/auction-items/{item}/confirm-delivery', [FulfillmentController::class, 'confirmAuctionDelivery'])->name('auction-items.confirm-delivery');
        Route::post('/sell-now-offers/{offer}/confirm-payment',  [FulfillmentController::class, 'confirmSellNowPayment'])->name('sell-now-offers.confirm-payment');
        Route::post('/sell-now-offers/{offer}/confirm-delivery', [FulfillmentController::class, 'confirmSellNowDelivery'])->name('sell-now-offers.confirm-delivery');
        Route::post('/sell-now-offers/{offer}/close',            [FulfillmentController::class, 'closeSellNow'])->name('sell-now-offers.close');

        // Disputes
        Route::get('/disputes',                          [DisputeController::class, 'index'])->name('disputes.index');
        Route::get('/disputes/{dispute}',                [DisputeController::class, 'show'])->name('disputes.show');
        Route::post('/disputes',                         [DisputeController::class, 'store'])->name('disputes.store');
        Route::post('/disputes/{dispute}/assign',        [DisputeController::class, 'assign'])->name('disputes.assign');
        Route::post('/disputes/{dispute}/resolve',       [DisputeController::class, 'resolve'])->name('disputes.resolve');
        Route::post('/disputes/{dispute}/evidence',      [DisputeController::class, 'attachEvidence'])->name('disputes.evidence.store');

        // Ownership transfers (party to transfer sees own; admin sees all)
        Route::get('/ownership-transfers',                       [OwnershipTransferController::class, 'index'])->name('ownership-transfers.index');
        Route::get('/ownership-transfers/{ownershipTransfer}',   [OwnershipTransferController::class, 'show'])->name('ownership-transfers.show');

        // Artist profile — create / show / update own
        Route::get('/artist-profile',   [ProfileController::class, 'showArtistProfile'])->name('artist-profile.show');
        Route::post('/artist-profile',  [ProfileController::class, 'createArtistProfile'])->name('artist-profile.store');
        Route::patch('/artist-profile', [ProfileController::class, 'updateArtistProfile'])->name('artist-profile.update');

        // Gallery creation + update (admin/operator/manager)
        Route::post('/galleries',              [GalleryController::class, 'store'])->name('galleries.store');
        Route::patch('/galleries/{gallery}',   [GalleryController::class, 'update'])->name('galleries.update');

        // Evidence — admin/operator listing, show, verify
        Route::get('/evidence',                   [EvidenceController::class, 'index'])->name('evidence.index');
        Route::get('/evidence/{evidence}',         [EvidenceController::class, 'show'])->name('evidence.show');
        Route::post('/evidence/{evidence}/verify', [EvidenceController::class, 'verify'])->name('evidence.verify');

        // Artist applications
        Route::get('/artist-applications',                              [ArtistApplicationController::class, 'index'])->name('artist-applications.index');
        Route::get('/artist-applications/{application}',                [ArtistApplicationController::class, 'show'])->name('artist-applications.show');
        Route::post('/artist-applications',                             [ArtistApplicationController::class, 'store'])->name('artist-applications.store');
        Route::post('/artist-applications/{application}/approve',       [ArtistApplicationController::class, 'approve'])->name('artist-applications.approve');
        Route::post('/artist-applications/{application}/reject',        [ArtistApplicationController::class, 'reject'])->name('artist-applications.reject');

        // Donation recipients — admin create/update
        Route::post('/donation-recipients',                          [DonationRecipientController::class, 'store'])->name('donation-recipients.store');
        Route::patch('/donation-recipients/{donationRecipient}',     [DonationRecipientController::class, 'update'])->name('donation-recipients.update');

        // Admin audit log (admin/operator)
        Route::get('/admin/audit-log',          [AdminAuditLogController::class, 'index'])->name('admin.audit-log.index');
        Route::get('/admin/audit-log/{adminAuditLog}', [AdminAuditLogController::class, 'show'])->name('admin.audit-log.show');

        // Settlements — admin read-only view
        Route::get('/admin/settlements',       [SettlementController::class, 'index'])->name('admin.settlements.index');
        Route::get('/admin/settlements/{id}',  [SettlementController::class, 'show'])->name('admin.settlements.show');

        // Reconciliation runs — admin trigger and view
        Route::get('/admin/reconciliation-runs',                        [ReconciliationController::class, 'index'])->name('admin.reconciliation.index');
        Route::get('/admin/reconciliation-runs/{reconciliationRun}',    [ReconciliationController::class, 'show'])->name('admin.reconciliation.show');
        Route::post('/admin/reconciliation-runs',                       [ReconciliationController::class, 'store'])->name('admin.reconciliation.store');

        // ArtMetro route admin (admin/operator)
        Route::post('/admin/artmetro/routes',                                        [ArtmetroAdminController::class, 'store'])->name('admin.artmetro.routes.store');
        Route::patch('/admin/artmetro/routes/{route}',                               [ArtmetroAdminController::class, 'update'])->name('admin.artmetro.routes.update');
        Route::patch('/admin/artmetro/routes/{route}/publish',                       [ArtmetroAdminController::class, 'togglePublish'])->name('admin.artmetro.routes.publish');
        Route::post('/admin/artmetro/routes/{route}/stops',                          [ArtmetroAdminController::class, 'addStop'])->name('admin.artmetro.routes.stops.store');
        Route::delete('/admin/artmetro/routes/{route}/stops/{stop}',                 [ArtmetroAdminController::class, 'removeStop'])->name('admin.artmetro.routes.stops.destroy');

        // Book publish/unpublish (admin/operator)
        Route::patch('/books/{book}/publish',        [LibraryController::class, 'publish'])->name('books.publish');

        // Book authors — owner or admin manages author list and royalty splits
        Route::get('/books/{book}/authors',                         [BookAuthorController::class, 'index'])->name('books.authors.index');
        Route::post('/books/{book}/authors',                        [BookAuthorController::class, 'store'])->name('books.authors.store');
        Route::patch('/books/{book}/authors/{bookAuthor}',          [BookAuthorController::class, 'update'])->name('books.authors.update');
        Route::delete('/books/{book}/authors/{bookAuthor}',         [BookAuthorController::class, 'destroy'])->name('books.authors.destroy');

        // User admin — admin list and role management
        Route::get('/admin/users',          [UserAdminController::class, 'index'])->name('admin.users.index');
        Route::get('/admin/users/{user}',   [UserAdminController::class, 'show'])->name('admin.users.show');
        Route::patch('/admin/users/{user}', [UserAdminController::class, 'update'])->name('admin.users.update');

        // Ledger + refunds — admin read-only
        Route::get('/admin/ledger',   [LedgerController::class, 'index'])->name('admin.ledger.index');
        Route::get('/admin/refunds',  [LedgerController::class, 'refunds'])->name('admin.refunds.index');

        // Split profiles — admin create, list, deprecate
        Route::get('/admin/split-profiles',               [SplitProfileController::class, 'index'])->name('admin.split-profiles.index');
        Route::post('/admin/split-profiles',              [SplitProfileController::class, 'store'])->name('admin.split-profiles.store');
        Route::patch('/admin/split-profiles/{id}/deprecate', [SplitProfileController::class, 'deprecate'])->name('admin.split-profiles.deprecate');
        Route::patch('/books/{book}/unpublish', [LibraryController::class, 'unpublish'])->name('books.unpublish');

        // Library — authenticated purchase, entitlement, and admin grant
        Route::get('/my-books',                                        [LibraryController::class, 'myBooks'])->name('books.my');
        Route::get('/books/{book}/entitlement',                        [LibraryController::class, 'entitlement'])->name('books.entitlement');
        Route::post('/books/{book}/purchase',                          [LibraryController::class, 'purchase'])->name('books.purchase');
        Route::post('/books/{book}/grant',                             [LibraryController::class, 'grant'])->name('books.grant');
        Route::delete('/book-entitlements/{bookEntitlement}',          [LibraryController::class, 'revokeEntitlement'])->name('books.entitlements.revoke');

        // Reserves — seller decision after reserve not met
        Route::get('/reserves/{reserve}',                              [ReserveController::class, 'show'])->name('reserves.show');
        Route::post('/reserves/{reserve}/waive',                       [ReserveController::class, 'waive'])->name('reserves.waive');
        Route::post('/reserves/{reserve}/counter-offer',               [ReserveController::class, 'counterOffer'])->name('reserves.counter-offer');

        // Max bid (proxy bidding)
        Route::post('/auctions/{auction}/items/{item}/max-bid',        [V1AuctionController::class, 'placeMaxBid'])->name('auctions.items.max-bid');

        // Exhibitions — SDG tagging (gallery staff or admin)
        Route::put('/exhibitions/{exhibition}/sdg-tags', [ExhibitionController::class, 'tagSdgs'])->name('exhibitions.sdg-tags');

        // Impact events — record against approved SDG claim (admin/operator)
        Route::post('/sdg-claims/{claim}/impact-events', [ImpactRecordController::class, 'store'])->name('sdg-claims.impact-events.store');

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
            Route::post('/transfer-outbox/{id}/retry', [OperationsController::class, 'retryTransfer'])->name('transfer-outbox.retry');
        });

        // Exhibitions — PATCH (gallery staff or admin)
        Route::patch('/exhibitions/{exhibition}', [ExhibitionController::class, 'update'])->name('exhibitions.update');

        // Admin settlement lines — list and manual disburse trigger
        Route::get('/admin/settlement-lines',                      [AdminPayoutController::class, 'lines'])->name('admin.settlement-lines.index');
        Route::post('/admin/settlement-lines/{id}/disburse',       [AdminPayoutController::class, 'disburse'])->name('admin.settlement-lines.disburse');

        // Admin book management — create/update (publish via LibraryController)
        Route::post('/admin/books',        [AdminBookController::class, 'store'])->name('admin.books.store');
        Route::patch('/admin/books/{book}', [AdminBookController::class, 'update'])->name('admin.books.update');

        // Consignment terminate (cancel by owner/consignor/admin)
        Route::post('/consignments/{consignment}/terminate', [ConsignmentController::class, 'cancel'])->name('consignments.terminate');

        // Admin auction rulesets — CRUD
        Route::get('/admin/rulesets',                 [AdminRulesetController::class, 'index'])->name('admin.rulesets.index');
        Route::get('/admin/rulesets/{ruleset}',       [AdminRulesetController::class, 'show'])->name('admin.rulesets.show');
        Route::post('/admin/rulesets',                [AdminRulesetController::class, 'store'])->name('admin.rulesets.store');
        Route::patch('/admin/rulesets/{ruleset}',     [AdminRulesetController::class, 'update'])->name('admin.rulesets.update');

        // Admin transfer outbox — read-only view
        Route::get('/admin/transfer-outbox',          [AdminTransferOutboxController::class, 'index'])->name('admin.transfer-outbox.index');
        Route::get('/admin/transfer-outbox/{id}',     [AdminTransferOutboxController::class, 'show'])->name('admin.transfer-outbox.show');

        // Admin connected-account payouts — read-only reconciliation view
        Route::get('/admin/connected-payouts',        [AdminConnectedPayoutController::class, 'index'])->name('admin.connected-payouts.index');
        Route::get('/admin/connected-payouts/{id}',   [AdminConnectedPayoutController::class, 'show'])->name('admin.connected-payouts.show');

        // Admin auction management — create, update, manage items
        Route::post('/admin/auctions',                               [AdminAuctionController::class, 'store'])->name('admin.auctions.store');
        Route::patch('/admin/auctions/{auction}',                    [AdminAuctionController::class, 'update'])->name('admin.auctions.update');
        Route::post('/admin/auctions/{auction}/items',               [AdminAuctionController::class, 'addItem'])->name('admin.auctions.items.store');
        Route::delete('/admin/auctions/{auction}/items/{item}',      [AdminAuctionController::class, 'removeItem'])->name('admin.auctions.items.destroy');
        Route::post('/admin/auctions/{auction}/publish',             [AdminAuctionController::class, 'publish'])->name('admin.auctions.publish');
        Route::post('/admin/auctions/{auction}/open',                [AdminAuctionController::class, 'open'])->name('admin.auctions.open');
        Route::post('/admin/auctions/{auction}/close',               [AdminAuctionController::class, 'close'])->name('admin.auctions.close');

        // Book files — metadata management (owner or admin)
        Route::get('/books/{book}/files',                          [BookFileController::class, 'index'])->name('books.files.index');
        Route::post('/books/{book}/files',                         [BookFileController::class, 'store'])->name('books.files.store');
        Route::patch('/books/{book}/files/{bookFile}',             [BookFileController::class, 'update'])->name('books.files.update');
        Route::delete('/books/{book}/files/{bookFile}',            [BookFileController::class, 'destroy'])->name('books.files.destroy');

        // Book file presigned upload/download (S3 two-phase upload + entitlement-gated download)
        Route::post('/books/{book}/files/upload-intent',           [BookFileUploadController::class, 'uploadIntent'])->name('books.files.upload-intent');
        Route::post('/book-files/{bookFile}/complete',             [BookFileUploadController::class, 'complete'])->name('book-files.complete');
        Route::get('/my-books/{book}/download-url',                [BookFileUploadController::class, 'downloadUrl'])->name('my-books.download-url');
    });
});

