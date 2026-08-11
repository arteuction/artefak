<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactController;
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
Route::middleware('auth')->group(function (): void {
    Route::post('/auctions/{auction}/items/{item}/bids', [BidController::class, 'store']);
});

// ArtMetro — QR scan (public) + visit beacon (public, 202)
Route::get('/artifacts/{qrToken}/scan', [ArtifactController::class, 'scan']);
Route::post('/artifacts/{artifact}/visit', [ArtifactController::class, 'visit']);
