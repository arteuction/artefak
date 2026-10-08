<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Donation\RecordDonation;
use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\DonorFiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class DonationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Donation::query();

        if ($user->role !== 'admin') {
            $query->where('donor_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('donation_recipient_id')) {
            $query->where('donation_recipient_id', $request->integer('donation_recipient_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate(25),
        ]);
    }

    public function show(Request $request, Donation $donation): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $donation->donor_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => $donation]);
    }

    /** POST /api/v1/donations */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'donation_recipient_id' => ['required', 'integer', 'exists:donation_recipients,id'],
            'donated_cents'         => ['required', 'integer', 'min:100'],
            'impact_project_id'     => ['nullable', 'integer', 'exists:impact_projects,id'],
            'source.art_lot_id'     => ['nullable', 'integer', 'exists:art_lots,id'],
            'source.auction_item_id'=> ['nullable', 'integer', 'exists:auction_items,id'],
            'source.sell_now_offer_id' => ['nullable', 'integer', 'exists:sell_now_offers,id'],
        ]);

        $recipient = DonationRecipient::findOrFail($data['donation_recipient_id']);

        try {
            $donation = (new RecordDonation())->execute(
                recipient:       $recipient,
                donor:           $request->user(),
                donatedCents:    (int) $data['donated_cents'],
                idempotencyKey:  uniqid('donation_', true),
                source:          $data['source'] ?? [],
                impactProjectId: isset($data['impact_project_id']) ? (int) $data['impact_project_id'] : null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $donation], 201);
    }

    /**
     * GET /api/v1/donations/tax-receipt/{year?}
     *
     * Donor's own ЗКПО чл.31 documentation for the given fiscal year.
     * Returns the DonorFiscalYear record and itemized confirmed donations.
     */
    public function taxReceipt(Request $request, int $year = 0): JsonResponse
    {
        if ($year === 0) {
            $year = (int) now()->format('Y');
        }

        $user = $request->user();

        $fiscal = DonorFiscalYear::where('donor_id', $user->id)
            ->where('fiscal_year', $year)
            ->first();

        $donations = Donation::where('donor_id', $user->id)
            ->where('status', 'confirmed')
            ->whereYear('donated_at', $year)
            ->with('recipient:id,name,eik')
            ->orderBy('donated_at')
            ->get(['id', 'donation_recipient_id', 'donated_cents', 'currency', 'impact_project_id', 'donated_at']);

        return response()->json([
            'fiscal_year'  => $year,
            'summary'      => $fiscal,
            'donations'    => $donations,
        ]);
    }
}
