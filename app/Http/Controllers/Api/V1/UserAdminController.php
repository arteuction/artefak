<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin user management.
 */
final class UserAdminController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/users
     *
     * Paginated user list. Filterable by role, email substring.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = User::query()->select(['id', 'name', 'email', 'role', 'created_at']);

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->input('email') . '%');
        }

        return response()->json($query->orderByDesc('created_at')->paginate(50));
    }

    /**
     * GET /api/v1/admin/users/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'role', 'created_at', 'updated_at'])]);
    }

    /**
     * PATCH /api/v1/admin/users/{user}
     *
     * Admin updates a user's role.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'role' => ['required', 'in:buyer,artist,admin'],
        ]);

        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update($data);

        return response()->json(['data' => $user->fresh()->only(['id', 'name', 'email', 'role'])]);
    }
}
