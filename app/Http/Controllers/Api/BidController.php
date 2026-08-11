<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Auction\BidRejected;
use App\Domain\Auction\PlaceBid;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BidController extends Controller
{
    public function __construct(
        private readonly PlaceBid $placeBid,
    ) {}

    /**
     * POST /api/auctions/{auction}/items/{item}/bids
     *
     * Body:
     *   amount_cents          int   required
     *   payment_method_id     string  required  (Stripe PaymentMethod ID)
     */
    public function store(Request $request, Auction $auction, AuctionItem $item): JsonResponse
    {
        abort_if($item->auction_id !== $auction->id, 404);
        abort_if(! $auction->isLive(), 422, 'Auction is not live.');

        $data = $request->validate([
            'amount_cents'      => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'string', 'starts_with:pm_'],
        ]);

        try {
            $bid = $this->placeBid->execute(
                item:                   $item,
                bidderId:               $request->user()->id,
                amountCents:            (int) $data['amount_cents'],
                stripePaymentMethodId:  $data['payment_method_id'],
                ipAddress:              $request->ip() ?? '',
            );
        } catch (BidRejected $e) {
            throw ValidationException::withMessages(['amount_cents' => $e->getMessage()]);
        }

        return response()->json($bid, 201);
    }
}
