<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Asset\ActivateConsignment;
use App\Domain\Asset\ApproveConsignment;
use App\Domain\Asset\CreateConsignment;
use App\Domain\Asset\CreateLotFromConsignment;
use App\Domain\Asset\RequestConsignmentChanges;
use Illuminate\Support\Facades\DB;
use App\Models\ArtLot;
use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ConsignmentController extends Controller
{
    /** GET /api/v1/consignments */
    public function index(Request $request): JsonResponse
    {
        $query = Consignment::with(['artwork:id,title,slug', 'gallery:id,name'])
            ->where(function ($q) use ($request) {
                $userId = $request->user()->id;
                $q->where('owner_id', $userId)
                  ->orWhere('consignor_id', $userId);
            });

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /** GET /api/v1/consignments/{consignment} */
    public function show(Request $request, Consignment $consignment): JsonResponse
    {
        $userId = $request->user()->id;

        if ($consignment->owner_id !== $userId && $consignment->consignor_id !== $userId) {
            abort(403);
        }

        $consignment->load(['artwork', 'owner:id,name', 'consignor:id,name', 'gallery:id,name,slug']);

        return response()->json($consignment);
    }

    /** POST /api/v1/consignments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'artwork_id'     => ['required', 'integer', 'exists:artworks,id'],
            'consignor_id'   => ['required', 'integer', 'exists:users,id'],
            'gallery_id'     => ['nullable', 'integer', 'exists:galleries,id'],
            'commission_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'starts_at'      => ['nullable', 'date'],
            'ends_at'        => ['nullable', 'date', 'after:starts_at'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]);

        $artwork   = Artwork::findOrFail($data['artwork_id']);
        $consignor = User::findOrFail($data['consignor_id']);
        $gallery   = isset($data['gallery_id']) ? Gallery::findOrFail($data['gallery_id']) : null;

        try {
            $consignment = (new CreateConsignment())->execute(
                artwork: $artwork,
                owner: $request->user(),
                consignor: $consignor,
                commissionBps: (int) ($data['commission_bps'] ?? 0),
                gallery: $gallery,
                startsAt: isset($data['starts_at']) ? new \DateTime($data['starts_at']) : null,
                endsAt: isset($data['ends_at']) ? new \DateTime($data['ends_at']) : null,
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($consignment, 201);
    }

    /** POST /api/v1/consignments/{consignment}/approve */
    public function approve(Request $request, Consignment $consignment): JsonResponse
    {
        try {
            $consignment = (new ApproveConsignment())->execute($consignment, $request->user());
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($consignment);
    }

    /** POST /api/v1/consignments/{consignment}/request-changes */
    public function requestChanges(Request $request, Consignment $consignment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            (new RequestConsignmentChanges())->execute($consignment, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['status' => 'changes_requested']);
    }

    /** POST /api/v1/consignments/{consignment}/create-lot */
    public function createLot(Request $request, Consignment $consignment): JsonResponse
    {
        $data = $request->validate([
            'sale_mode'            => ['required', 'in:auction,sell_now,gallery,private,hybrid'],
            'starting_bid_cents'   => ['nullable', 'integer', 'min:0'],
            'reserve_price_cents'  => ['nullable', 'integer', 'min:0'],
            'buy_now_price_cents'  => ['nullable', 'integer', 'min:1'],
            'split_profile_key'    => ['nullable', 'string', 'max:80'],
            'currency'             => ['nullable', 'string', 'size:3'],
        ]);

        try {
            $lot = (new CreateLotFromConsignment())->execute(
                consignment:      $consignment,
                createdBy:        $request->user(),
                saleMode:         $data['sale_mode'],
                startingBidCents: (int) ($data['starting_bid_cents'] ?? 0),
                reservePriceCents: isset($data['reserve_price_cents']) ? (int) $data['reserve_price_cents'] : null,
                buyNowPriceCents:  isset($data['buy_now_price_cents']) ? (int) $data['buy_now_price_cents'] : null,
                splitProfileKey:   $data['split_profile_key'] ?? 'social_pilot_45_45_10',
                currency:          $data['currency'] ?? 'EUR',
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($lot->load('artwork:id,title,slug'), 201);
    }

    /** POST /api/v1/consignments/{consignment}/activate */
    public function activate(Request $request, Consignment $consignment): JsonResponse
    {
        if ($consignment->owner_id !== $request->user()->id
            && $consignment->consignor_id !== $request->user()->id) {
            abort(403);
        }

        try {
            $consignment = (new ActivateConsignment())->execute($consignment);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($consignment);
    }

    /**
     * POST /api/v1/consignments/{consignment}/cancel
     *
     * Owner or consignor can cancel a draft/pending/active consignment.
     * Cannot cancel if there are non-terminal ArtLots attached.
     */
    public function cancel(Request $request, Consignment $consignment): JsonResponse
    {
        $user = $request->user();
        $isOwner = $consignment->owner_id === $user->id || $consignment->consignor_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);

        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }

        if ($consignment->status === 'terminated') {
            return response()->json(['message' => 'Already terminated.', 'data' => $consignment]);
        }

        if ($consignment->status === 'completed') {
            abort(422, 'A completed consignment cannot be terminated.');
        }

        // Guard: active art lots block cancellation
        $activeLots = ArtLot::where('consignment_id', $consignment->id)
            ->whereNotIn('status', ['sold', 'unsold', 'archived', 'draft'])
            ->count();

        if ($activeLots > 0) {
            abort(422, "Cannot cancel: consignment has {$activeLots} active lot(s).");
        }

        DB::transaction(function () use ($consignment): void {
            $consignment->update(['status' => 'terminated']);

            // Archive any draft lots tied to this consignment
            ArtLot::where('consignment_id', $consignment->id)
                ->where('status', 'draft')
                ->update(['status' => 'archived', 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Consignment terminated.', 'data' => $consignment->fresh()]);
    }
}
