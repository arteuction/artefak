<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Asset\ActivateConsignment;
use App\Domain\Asset\CreateConsignment;
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
}
