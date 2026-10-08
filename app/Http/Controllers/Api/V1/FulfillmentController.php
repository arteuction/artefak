<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fulfillment\ConfirmAuctionDelivery;
use App\Domain\Fulfillment\ConfirmSellNowDelivery;
use App\Domain\Fulfillment\ConfirmSellNowPayment;
use App\Domain\Fulfillment\ShipAuctionItem;
use App\Http\Controllers\Controller;
use App\Models\AuctionItem;
use App\Models\SellNowOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Fulfillment lifecycle endpoints.
 *
 * Auction path:  paid → shipped (gallery) → delivered (gallery/buyer)
 * SellNow path:  accepted → paid (admin/Stripe) → delivered (gallery/admin)
 */
final class FulfillmentController extends Controller
{
    /**
     * POST /api/v1/auction-items/{item}/ship
     *
     * Gallery records shipment for an auction item.
     */
    public function shipAuctionItem(Request $request, AuctionItem $item): JsonResponse
    {
        $data = $request->validate([
            'carrier'         => ['required', 'string', 'max:100'],
            'tracking_number' => ['required', 'string', 'max:200'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $fulfillment = (new ShipAuctionItem())->execute(
                item:           $item,
                carrier:        $data['carrier'],
                trackingNumber: $data['tracking_number'],
                notes:          $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($fulfillment, 200);
    }

    /**
     * POST /api/v1/auction-items/{item}/confirm-delivery
     *
     * Records auction item delivery and creates ownership transfer.
     */
    public function confirmAuctionDelivery(Request $request, AuctionItem $item): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $fulfillment = (new ConfirmAuctionDelivery())->execute($item, $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($fulfillment, 200);
    }

    /**
     * POST /api/v1/sell-now-offers/{offer}/confirm-payment
     *
     * Admin/operator marks sell-now offer payment confirmed (accepted → paid).
     * Normally triggered by Stripe webhook; this endpoint is for ops manual override.
     */
    public function confirmSellNowPayment(Request $request, SellNowOffer $offer): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        try {
            $offer = (new ConfirmSellNowPayment())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer, 200);
    }

    /**
     * POST /api/v1/sell-now-offers/{offer}/confirm-delivery
     *
     * Records sell-now offer delivery (paid → delivered).
     */
    public function confirmSellNowDelivery(Request $request, SellNowOffer $offer): JsonResponse
    {
        try {
            $offer = (new ConfirmSellNowDelivery())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer, 200);
    }
}
