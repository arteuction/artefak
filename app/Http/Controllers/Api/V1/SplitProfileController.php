<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin management of split profiles (settlement split configuration).
 * Once a version is 'superseded' it MUST NOT be modified.
 */
final class SplitProfileController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/split-profiles
     *
     * List all split profiles, optionally filtered by key or status.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('split_profiles')->orderBy('profile_key')->orderByDesc('version');

        if ($request->filled('profile_key')) {
            $query->where('profile_key', $request->input('profile_key'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $total = $query->count();
        $items = $query->limit(100)->get();

        return response()->json(['data' => $items, 'total' => $total]);
    }

    /**
     * POST /api/v1/admin/split-profiles
     *
     * Create a new split profile version.
     * artist_bps + fund_bps + ops_bps must equal 10000.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'profile_key'    => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'],
            'artist_bps'     => ['required', 'integer', 'min:0', 'max:10000'],
            'fund_bps'       => ['required', 'integer', 'min:0', 'max:10000'],
            'ops_bps'        => ['required', 'integer', 'min:0', 'max:10000'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['required', 'date'],
        ]);

        $total = $data['artist_bps'] + $data['fund_bps'] + $data['ops_bps'];
        if ($total !== 10000) {
            abort(422, "artist_bps + fund_bps + ops_bps must equal 10000; got {$total}.");
        }

        // Next version for this key
        $maxVersion = DB::table('split_profiles')
            ->where('profile_key', $data['profile_key'])
            ->max('version') ?? 0;

        $id = DB::table('split_profiles')->insertGetId([
            'profile_key'    => $data['profile_key'],
            'version'        => $maxVersion + 1,
            'artist_bps'     => $data['artist_bps'],
            'fund_bps'       => $data['fund_bps'],
            'ops_bps'        => $data['ops_bps'],
            'description'    => $data['description'] ?? null,
            'status'         => 'active',
            'effective_from' => $data['effective_from'],
            'created_by'     => $request->user()->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $row = DB::table('split_profiles')->find($id);

        return response()->json(['data' => $row], 201);
    }

    /**
     * PATCH /api/v1/admin/split-profiles/{id}/deprecate
     *
     * Mark a split profile version as deprecated (no new ArtLots can use it).
     * Cannot modify 'superseded' profiles.
     */
    public function deprecate(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);

        $row = DB::table('split_profiles')->find($id);

        if (! $row) {
            abort(404, "Split profile #{$id} not found.");
        }

        if ($row->status === 'superseded') {
            abort(422, 'Cannot modify a superseded split profile version.');
        }

        DB::table('split_profiles')->where('id', $id)->update([
            'status'     => 'deprecated',
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('split_profiles')->find($id)]);
    }
}
