<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SellNowOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

/**
 * POST /api/v1/sell-now-offers/{offer}/checkout-session
 *
 * Creates (or retrieves an existing) Stripe Checkout Session for the buyer
 * to complete payment on an accepted sell-now offer.
 *
 * Idempotent: if a session already exists and is still open, returns its URL
 * without creating a new one.
 */
final class SellNowCheckoutController extends Controller
{
    public function create(Request $request, SellNowOffer $offer): JsonResponse
    {
        $user = $request->user();

        if ($offer->buyer_id !== $user->id) {
            abort(403);
        }

        if ($offer->status !== 'accepted') {
            abort(422, 'Payment is only available for accepted offers.');
        }

        // Idempotency: return existing session URL if still open
        if ($offer->stripe_checkout_session_id !== null) {
            $stripe  = new StripeClient(config('services.stripe.secret'));
            $session = $stripe->checkout->sessions->retrieve($offer->stripe_checkout_session_id);

            if ($session->status === 'open') {
                return response()->json(['url' => $session->url]);
            }
        }

        $stripe   = new StripeClient(config('services.stripe.secret'));
        $appUrl   = rtrim((string) config('app.url'), '/');
        $artwork  = $offer->artLot?->artwork;
        $title    = $artwork ? $artwork->title : "Sell Now Offer #{$offer->id}";

        $session = $stripe->checkout->sessions->create([
            'mode'           => 'payment',
            'currency'       => strtolower($offer->currency),
            'line_items'     => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($offer->currency),
                    'unit_amount'  => (int) $offer->agreed_price_cents,
                    'product_data' => ['name' => $title],
                ],
            ]],
            'payment_intent_data' => [
                'metadata' => [
                    'sell_now_offer_id' => (string) $offer->id,
                    'art_lot_id'        => (string) $offer->art_lot_id,
                ],
            ],
            'metadata' => [
                'sell_now_offer_id' => (string) $offer->id,
            ],
            'success_url' => "{$appUrl}/offers?payment=success",
            'cancel_url'  => "{$appUrl}/offers?payment=cancelled",
        ]);

        $offer->update(['stripe_checkout_session_id' => $session->id]);

        return response()->json(['url' => $session->url]);
    }
}
