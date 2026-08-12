<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\ArtmetroRouteController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\BidController;
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
