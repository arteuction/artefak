<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auction\ReAuthorizeBid;
use App\Models\AuctionItem;
use App\Models\Bid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/auction-items/{item}/re-authorize
 *
 * Re-authorizes payment for an expired winning bid.
 * Only the winning bidder may call this endpoint.
 */
final class BidReAuthorizationController
{
    public function store(
        Request      $request,
        AuctionItem  $item,
        ReAuthorizeBid $reAuthorize,
    ): JsonResponse {
        $request->validate([
            'payment_method_id' => 'required|string|starts_with:pm_',
        ]);

        $user = $request->user();

        // Find the caller's winning bid on this item
        $bid = Bid::where('auction_item_id', $item->id)
            ->where('user_id', $user->id)
            ->where('status', 'won')
            ->first();

        if ($bid === null) {
            return response()->json(['message' => 'No winning bid found for this item.'], 404);
        }

        try {
            $bid = $reAuthorize->execute(
                item:                   $item,
                bid:                    $bid,
                callerId:               $user->id,
                stripePaymentMethodId:  (string) $request->string('payment_method_id'),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json(['message' => 'Card declined: ' . $e->getMessage()], 402);
        }

        return response()->json([
            'bid' => [
                'id'                       => $bid->id,
                'payment_status'           => $bid->payment_status,
                'authorization_expires_at' => $bid->authorization_expires_at?->toIso8601String(),
            ],
        ]);
    }
}
