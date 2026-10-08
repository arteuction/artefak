<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DonationRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Donation recipients — public list, admin management.
 */
final class DonationRecipientController extends Controller
{
    /**
     * GET /api/v1/donation-recipients
     *
     * Public: active recipients only.
     * Admin: all statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DonationRecipient::query();

        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'operator'], true)) {
            $query->where('status', 'active');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    /**
     * GET /api/v1/donation-recipients/{recipient}
     */
    public function show(DonationRecipient $donationRecipient): JsonResponse
    {
        return response()->json(['data' => $donationRecipient]);
    }

    /**
     * POST /api/v1/donation-recipients
     *
     * Admin creates a new donation recipient.
     */
    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:200'],
            'eik'               => ['required', 'string', 'max:20', 'unique:donation_recipients,eik'],
            'legal_type'        => ['required', 'in:ngo,nhi,municipality,state,religious,other'],
            'eligibility_basis' => ['required', 'in:ZKPO_ART31_1,ZKPO_ART31_3_PATRONAGE,ZKPO_ART31_2_NHI_CHILD_TREATMENT,ZKPO_ART31_2_ASSISTED_REPRODUCTION'],
            'deduction_bps'     => ['required', 'integer', 'min:0', 'max:10000'],
            'stripe_account_id' => ['nullable', 'string', 'max:100'],
            'status'            => ['nullable', 'in:active,inactive'],
        ]);

        $recipient = DonationRecipient::create([
            ...$data,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json($recipient, 201);
    }

    /**
     * PATCH /api/v1/donation-recipients/{recipient}
     *
     * Admin updates recipient details.
     */
    public function update(Request $request, DonationRecipient $donationRecipient): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:200'],
            'legal_type'        => ['sometimes', 'in:ngo,nhi,municipality,state,religious,other'],
            'eligibility_basis' => ['sometimes', 'in:ZKPO_ART31_1,ZKPO_ART31_3_PATRONAGE,ZKPO_ART31_2_NHI_CHILD_TREATMENT,ZKPO_ART31_2_ASSISTED_REPRODUCTION'],
            'deduction_bps'     => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'stripe_account_id' => ['nullable', 'string', 'max:100'],
            'status'            => ['sometimes', 'in:active,inactive'],
        ]);

        $donationRecipient->update($data);

        return response()->json($donationRecipient->fresh());
    }
}
