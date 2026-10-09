<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuctionRuleset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of auction rulesets.
 *
 * Rulesets are reusable auction policy objects (bid increments, anti-sniping,
 * reserve rules, etc.). They are versioned by creating new rows, not updating
 * live ones. Updating is allowed only if no live/closed auction references the ruleset.
 */
final class AdminRulesetController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /** GET /api/v1/admin/rulesets */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $rulesets = AuctionRuleset::orderByDesc('id')->get();
        return response()->json(['data' => $rulesets]);
    }

    /** GET /api/v1/admin/rulesets/{ruleset} */
    public function show(Request $request, AuctionRuleset $ruleset): JsonResponse
    {
        $this->requireAdmin($request);
        return response()->json(['data' => $ruleset]);
    }

    /** POST /api/v1/admin/rulesets */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name'                        => ['required', 'string', 'max:100'],
            'default_bid_increment_cents' => ['nullable', 'integer', 'min:100'],
            'anti_sniping_seconds'        => ['nullable', 'integer', 'min:0'],
            'extension_seconds'           => ['nullable', 'integer', 'min:0'],
            'proxy_bid_enabled'           => ['boolean'],
            'reserve_enabled'             => ['boolean'],
            'counter_offer_enabled'       => ['boolean'],
            'seller_approval_required'    => ['boolean'],
            'tie_policy'                  => ['nullable', 'in:first_wins,highest_wins,reject_tie'],
            'max_bid_policy'              => ['nullable', 'in:disabled,auto_increment,cap'],
        ]);

        $ruleset = AuctionRuleset::create($data);

        return response()->json(['data' => $ruleset], 201);
    }

    /**
     * PATCH /api/v1/admin/rulesets/{ruleset}
     *
     * Cannot update a ruleset that is attached to a live or closed auction.
     */
    public function update(Request $request, AuctionRuleset $ruleset): JsonResponse
    {
        $this->requireAdmin($request);

        $liveAuctions = $ruleset->auctions()->whereIn('status', ['live', 'closed'])->count();
        if ($liveAuctions > 0) {
            abort(422, 'Cannot update a ruleset attached to live or closed auctions.');
        }

        $data = $request->validate([
            'name'                        => ['sometimes', 'string', 'max:100'],
            'default_bid_increment_cents' => ['nullable', 'integer', 'min:100'],
            'anti_sniping_seconds'        => ['nullable', 'integer', 'min:0'],
            'extension_seconds'           => ['nullable', 'integer', 'min:0'],
            'proxy_bid_enabled'           => ['sometimes', 'boolean'],
            'reserve_enabled'             => ['sometimes', 'boolean'],
            'counter_offer_enabled'       => ['sometimes', 'boolean'],
            'seller_approval_required'    => ['sometimes', 'boolean'],
            'tie_policy'                  => ['nullable', 'in:first_wins,highest_wins,reject_tie'],
            'max_bid_policy'              => ['nullable', 'in:disabled,auto_increment,cap'],
        ]);

        $ruleset->update($data);

        return response()->json(['data' => $ruleset->fresh()]);
    }
}
