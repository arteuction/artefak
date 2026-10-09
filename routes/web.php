<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Marketplace SPA — catches all non-API, non-admin paths and hands them to React Router.
Route::get('/{any?}', function () {
    return view('marketplace');
})->where('any', '^(?!api|admin|stripe|docs).*')->name('marketplace');

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
