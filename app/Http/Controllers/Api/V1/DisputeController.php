<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dispute\AssignDispute;
use App\Domain\Dispute\AttachDisputeEvidence;
use App\Domain\Dispute\OpenDispute;
use App\Domain\Dispute\ResolveDispute;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DisputeController extends Controller
{
    /**
     * GET /api/v1/disputes
     * Party to the dispute sees own; admin sees all.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Dispute::query();

        if (! in_array($user->role, ['admin', 'operator'], true)) {
            $query->where('opened_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /** GET /api/v1/disputes/{dispute} */
    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['admin', 'operator'], true) && $dispute->opened_by !== $user->id) {
            abort(403);
        }

        $dispute->load(['opener:id,name', 'assignee:id,name', 'evidence']);

        return response()->json($dispute);
    }

    /** POST /api/v1/disputes */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'                  => ['required', Rule::in(Dispute::TYPES)],
            'description'           => ['required', 'string', 'max:5000'],
            'art_lot_id'            => ['nullable', 'integer', 'exists:art_lots,id'],
            'auction_item_id'       => ['nullable', 'integer', 'exists:auction_items,id'],
            'sell_now_offer_id'     => ['nullable', 'integer', 'exists:sell_now_offers,id'],
            'ownership_transfer_id' => ['nullable', 'integer', 'exists:ownership_transfers,id'],
        ]);

        try {
            $dispute = (new OpenDispute())->execute(
                openedBy:    $request->user(),
                type:        $data['type'],
                description: $data['description'],
                subject:     array_filter([
                    'art_lot_id'            => $data['art_lot_id'] ?? null,
                    'auction_item_id'       => $data['auction_item_id'] ?? null,
                    'sell_now_offer_id'     => $data['sell_now_offer_id'] ?? null,
                    'ownership_transfer_id' => $data['ownership_transfer_id'] ?? null,
                ]),
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($dispute, 201);
    }

    /**
     * POST /api/v1/disputes/{dispute}/assign
     * Admin/operator assigns to a staff member.
     */
    public function assign(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $assignee = \App\Models\User::findOrFail((int) $data['user_id']);

        try {
            $dispute = (new AssignDispute())->execute($dispute, $assignee);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($dispute);
    }

    /**
     * POST /api/v1/disputes/{dispute}/resolve
     * Admin/operator resolves or dismisses.
     */
    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'outcome'    => ['required', Rule::in(['resolved', 'dismissed'])],
            'resolution' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $dispute = (new ResolveDispute())->execute(
                dispute:    $dispute,
                resolvedBy: $user,
                outcome:    $data['outcome'],
                resolution: $data['resolution'],
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($dispute);
    }

    /**
     * POST /api/v1/disputes/{dispute}/evidence
     * Party to dispute or admin may attach evidence.
     */
    public function attachEvidence(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['admin', 'operator'], true) && $dispute->opened_by !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'type'          => ['required', 'in:document,photo,message_log,condition_report,other'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'notes'         => ['nullable', 'string'],
        ]);

        try {
            $evidence = (new AttachDisputeEvidence())->execute(
                dispute:     $dispute,
                submittedBy: $user,
                type:        $data['type'],
                attributes:  [
                    'document_path' => $data['document_path'] ?? null,
                    'notes'         => $data['notes'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($evidence, 201);
    }
}
