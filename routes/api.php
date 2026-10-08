<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\ArtmetroRouteController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\BidController;
use App\Http\Controllers\Api\V1\ArtLotController;
use App\Http\Controllers\Api\V1\ArtworkController;
use App\Http\Controllers\Api\V1\AuctionController as V1AuctionController;
use App\Http\Controllers\Api\V1\ConsignmentController;
use App\Http\Controllers\Api\V1\DomainEventController;
use App\Http\Controllers\Api\V1\SellNowOfferController;
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

// Authenticated — bidding
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auctions/{auction}/items/{item}/bids', [BidController::class, 'store']);
});

// ArtMetro — QR artifact info (read-only, safe for crawlers/prefetch)
Route::get('/artifacts/{qrToken}', [ArtifactController::class, 'show']);

// ArtMetro — QR scan record (mutating, rate-limited: 30/min per IP)
Route::middleware('throttle:30,1')->group(function (): void {
    Route::post('/artifacts/{qrToken}/scans', [ArtifactController::class, 'recordScan']);
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

    // Artworks
    Route::get('/artworks',        [ArtworkController::class, 'index'])->name('artworks.index');
    Route::get('/artworks/{artwork}', [ArtworkController::class, 'show'])->name('artworks.show');

    // ArtLots
    Route::get('/art-lots',          [ArtLotController::class, 'index'])->name('art-lots.index');
    Route::get('/art-lots/{artLot}', [ArtLotController::class, 'show'])->name('art-lots.show');

    // Auctions (public read)
    Route::get('/auctions',                              [V1AuctionController::class, 'index'])->name('auctions.index');
    Route::get('/auctions/{auction}',                    [V1AuctionController::class, 'show'])->name('auctions.show');
    Route::get('/auctions/{auction}/items/{item}',       [V1AuctionController::class, 'item'])->name('auctions.item');

    // Authenticated mutations
    Route::middleware('auth:sanctum')->group(function (): void {

        // Artworks
        Route::post('/artworks', [ArtworkController::class, 'store'])->name('artworks.store');

        // ArtLots
        Route::post('/art-lots', [ArtLotController::class, 'store'])->name('art-lots.store');

        // Sell Now offers
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
        Route::post('/consignments/{consignment}/activate', [ConsignmentController::class, 'activate'])->name('consignments.activate');

        // Domain events — operator only
        Route::middleware('can:viewAny,App\Models\DomainEvent')->group(function (): void {
            Route::get('/domain-events', [DomainEventController::class, 'index'])->name('domain-events.index');
        });
    });
});
