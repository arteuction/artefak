<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auction\IssueCounterOffer;
use App\Domain\Auction\WaiveReserve;
use App\Http\Controllers\Controller;
use App\Models\Reserve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reserve management — seller decisions after reserve is not met.
 *
 * All mutations require the authenticated user to be the consignor/seller
 * (owner of the ArtLot's consignment) or admin/operator.
 */
final class ReserveController extends Controller
{
    /**
     * GET /api/v1/reserves/{reserve}
     *
     * Show reserve details. Visible to seller or admin.
     */
    public function show(Request $request, Reserve $reserve): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $reserve)) {
            abort(403);
        }

        $reserve->load(['auctionItem.artLot']);

        return response()->json($reserve);
    }

    /**
     * POST /api/v1/reserves/{reserve}/waive
     *
     * Seller accepts the highest bid despite reserve not being met.
     */
    public function waive(Request $request, Reserve $reserve): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $reserve)) {
            abort(403);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            (new WaiveReserve())->execute($reserve, $user->id, $data['notes'] ?? null);
        } catch (\InvalidArgumentException|\LogicException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($reserve->fresh());
    }

    /**
     * POST /api/v1/reserves/{reserve}/counter-offer
     *
     * Seller proposes a counter price between highest bid and reserve.
     */
    public function counterOffer(Request $request, Reserve $reserve): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $reserve)) {
            abort(403);
        }

        $data = $request->validate([
            'counter_offer_cents' => ['required', 'integer', 'min:1'],
            'expires_in_hours'    => ['nullable', 'integer', 'min:1', 'max:168'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            (new IssueCounterOffer())->execute(
                reserve:            $reserve,
                counterOfferCents:  (int) $data['counter_offer_cents'],
                decidedBy:          $user->id,
                expiresInHours:     (int) ($data['expires_in_hours'] ?? 48),
                notes:              $data['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($reserve->fresh());
    }

    private function canManage(\App\Models\User $user, Reserve $reserve): bool
    {
        if (in_array($user->role, ['admin', 'operator'], true)) {
            return true;
        }

        // Seller check: user owns the ArtLot's consignor slot
        $artLot = $reserve->auctionItem?->artLot;
        if ($artLot && $artLot->consignor_id === $user->id) {
            return true;
        }

        return false;
    }
}
