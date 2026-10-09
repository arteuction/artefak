<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\SellNowOfferData;
use App\Domain\SellNow\AcceptOffer;
use App\Domain\SellNow\CounterOffer;
use App\Domain\SellNow\RejectOffer;
use App\Domain\SellNow\SubmitOffer;
use App\Http\Controllers\Controller;
use App\Models\ArtLot;
use App\Models\SellNowOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SellNowOfferController extends Controller
{
    /**
     * GET /api/v1/art-lots/{artLot}/sell-now-offers
     *
     * Seller sees all offers on their lot; buyer sees only their own.
     */
    public function index(Request $request, ArtLot $artLot): JsonResponse
    {
        $userId = $request->user()->id;
        $query  = $artLot->sellNowOffers()->with('buyer:id,name');

        if ($artLot->consignor_id !== $userId) {
            // Not the seller — show only own offers
            $query->where('buyer_id', $userId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /** POST /api/v1/art-lots/{artLot}/sell-now-offers */
    public function store(Request $request, ArtLot $artLot): JsonResponse
    {
        $data = $request->validate([
            'offered_price_cents' => ['required', 'integer', 'min:1'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $offer = (new SubmitOffer())->execute(
                artLot: $artLot,
                buyer: $request->user(),
                offeredPriceCents: (int) $data['offered_price_cents'],
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(SellNowOfferData::fromOffer($offer), 201);
    }

    /** POST /api/v1/sell-now-offers/{offer}/counter */
    public function counter(Request $request, SellNowOffer $offer): JsonResponse
    {
        $data = $request->validate([
            'counter_price_cents' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $offer = (new CounterOffer())->execute($offer, (int) $data['counter_price_cents']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer);
    }

    /** POST /api/v1/sell-now-offers/{offer}/accept */
    public function accept(SellNowOffer $offer): JsonResponse
    {
        try {
            $offer = (new AcceptOffer())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer);
    }

    /** POST /api/v1/sell-now-offers/{offer}/reject */
    public function reject(SellNowOffer $offer): JsonResponse
    {
        try {
            $offer = (new RejectOffer())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer);
    }
}
