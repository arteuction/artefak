<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gallery\AddGalleryStaff;
use App\Domain\Gallery\RevokeGalleryStaff;
use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GalleryStaffController extends Controller
{
    /** GET /api/v1/galleries/{gallery}/staff */
    public function index(Gallery $gallery): JsonResponse
    {
        $staff = $gallery->activeStaff()->with('user:id,name,email')->get();

        return response()->json($staff);
    }

    /** POST /api/v1/galleries/{gallery}/staff */
    public function store(Request $request, Gallery $gallery): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role'    => ['required', 'in:owner,finance,curator,sales'],
        ]);

        try {
            $member = (new AddGalleryStaff())->execute(
                gallery:    $gallery,
                actingUser: $request->user(),
                newUser:    User::findOrFail($data['user_id']),
                role:       $data['role'],
            );
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($member, 201);
    }

    /**
     * PATCH /api/v1/galleries/{gallery}/staff/{staffMember}
     *
     * Update a staff member's role. Requires gallery manager or admin.
     */
    public function update(Request $request, Gallery $gallery, \App\Models\GalleryStaff $staffMember): JsonResponse
    {
        abort_if($staffMember->gallery_id !== $gallery->id, 404);

        $actingUser = $request->user();
        $isAdmin    = in_array($actingUser->role, ['admin', 'operator'], true);
        $isManager  = $gallery->hasRole($actingUser, 'manager') || $gallery->hasRole($actingUser, 'owner');

        if (! $isAdmin && ! $isManager) {
            abort(403);
        }

        $data = $request->validate([
            'role' => ['required', 'in:owner,finance,curator,sales'],
        ]);

        $staffMember->update($data);

        return response()->json($staffMember->fresh()->load('user:id,name'));
    }

    /** DELETE /api/v1/galleries/{gallery}/staff/{user} */
    public function destroy(Request $request, Gallery $gallery, User $user): JsonResponse
    {
        try {
            (new RevokeGalleryStaff())->execute(
                gallery:    $gallery,
                actingUser: $request->user(),
                targetUser: $user,
            );
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(null, 204);
    }
}
