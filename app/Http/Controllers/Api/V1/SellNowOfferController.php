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
        // Only the consignor (seller) of the lot may counter
        $lot = $offer->artLot;
        $userId = $request->user()->id;
        if ($lot->consignor_id !== $userId && ! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

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
    public function accept(Request $request, SellNowOffer $offer): JsonResponse
    {
        // Buyer accepts a counter; consignor accepts an original offer.
        // Either party may call accept — but never a third party.
        $lot    = $offer->artLot;
        $userId = $request->user()->id;
        $isParty = $offer->buyer_id === $userId || $lot->consignor_id === $userId;
        if (! $isParty && ! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        try {
            $offer = (new AcceptOffer())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer);
    }

    /**
     * GET /api/v1/my/sell-now-offers
     *
     * Authenticated buyer's own offers across all lots, with artwork title/slug.
     */
    public function myOffers(Request $request): JsonResponse
    {
        $offers = SellNowOffer::with(['artLot.artwork:id,title,slug'])
            ->where('buyer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($offers->through(function (SellNowOffer $offer) {
            $data = SellNowOfferData::fromOffer($offer);
            $artwork = $offer->artLot?->artwork;
            return array_merge($data->toArray(), [
                'artwork' => $artwork ? ['id' => $artwork->id, 'title' => $artwork->title, 'slug' => $artwork->slug] : null,
            ]);
        }));
    }

    /**
     * GET /api/v1/my/gallery-offers
     *
     * Pending sell-now offers on lots where the authenticated user is the consignor (gallery staff/admin).
     */
    public function galleryOffers(Request $request): JsonResponse
    {
        $offers = SellNowOffer::with(['artLot.artwork:id,title,slug'])
            ->whereHas('artLot', fn ($q) => $q->where('consignor_id', $request->user()->id))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($offers->through(function (SellNowOffer $offer) {
            $data = SellNowOfferData::fromOffer($offer);
            $artwork = $offer->artLot?->artwork;
            return array_merge($data->toArray(), [
                'artwork' => $artwork ? ['id' => $artwork->id, 'title' => $artwork->title, 'slug' => $artwork->slug] : null,
            ]);
        }));
    }

    /** POST /api/v1/sell-now-offers/{offer}/reject */
    public function reject(Request $request, SellNowOffer $offer): JsonResponse
    {
        // Either party (buyer or consignor) may reject; third parties cannot.
        $lot    = $offer->artLot;
        $userId = $request->user()->id;
        $isParty = $offer->buyer_id === $userId || $lot->consignor_id === $userId;
        if (! $isParty && ! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        try {
            $offer = (new RejectOffer())->execute($offer);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer);
    }
}
