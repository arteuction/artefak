<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consignment;
use App\Models\DomainEvent;
use App\Models\DonorFiscalYear;
use App\Models\Reserve;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only read layer for the operations console.
 *
 * All endpoints are guarded by the 'admin' gate (can:admin middleware).
 * Returns lightweight summaries — no large JSON blobs.
 */
final class OperationsController extends Controller
{
    /**
     * Snapshot of platform operational health.
     *
     * Returns:
     *   pending_domain_events:  count of domain events not yet consumed
     *   failed_outbox:          transfer_outbox rows in 'failed' status
     *   open_reserves:          reserves awaiting seller decision
     *   active_consignments:    currently active consignment count
     *   fy_incomplete_donors:   donors with incomplete FY documentation this year
     */
    public function summary(): JsonResponse
    {
        $currentYear = (int) now()->format('Y');

        return response()->json([
            'data' => [
                'pending_domain_events' => $this->pendingDomainEventCount(),
                'failed_outbox'         => $this->failedOutboxCount(),
                'open_reserves'         => Reserve::where('status', 'not_reached')->count(),
                'active_consignments'   => Consignment::where('status', 'active')->count(),
                'fy_incomplete_donors'  => DonorFiscalYear::where('fiscal_year', $currentYear)
                    ->where('documentation_status', 'incomplete')
                    ->distinct('donor_id')
                    ->count('donor_id'),
            ],
        ]);
    }

    /** Paginated pending domain events (oldest first — FIFO processing order). */
    public function pendingEvents(): JsonResponse
    {
        $events = DomainEvent::orderBy('id')
            ->paginate(50);

        return response()->json(['data' => $events]);
    }

    /** Failed transfer_outbox rows — operator can trigger retry. */
    public function failedOutbox(): JsonResponse
    {
        $rows = DB::table('transfer_outbox')
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->paginate(50);

        return response()->json(['data' => $rows]);
    }

    /** Open reserves awaiting seller decision. */
    public function openReserves(): JsonResponse
    {
        $reserves = Reserve::with(['auctionItem'])
            ->where('status', 'not_reached')
            ->orderBy('id')
            ->paginate(50);

        return response()->json(['data' => $reserves]);
    }

    /** Active consignments. */
    public function activeConsignments(): JsonResponse
    {
        $consignments = Consignment::with(['artwork', 'owner', 'gallery'])
            ->where('status', 'active')
            ->orderBy('ends_at')
            ->paginate(50);

        return response()->json(['data' => $consignments]);
    }

    /** Fiscal-year summaries for the given year (defaults to current). */
    public function fiscalYearSummaries(int $year = 0): JsonResponse
    {
        if ($year === 0) {
            $year = (int) now()->format('Y');
        }

        $summaries = DonorFiscalYear::where('fiscal_year', $year)
            ->orderBy('donor_id')
            ->paginate(100);

        return response()->json(['data' => $summaries]);
    }

    private function pendingDomainEventCount(): int
    {
        // Domain events not yet recorded in any consumer inbox
        return (int) DB::table('domain_events')
            ->whereNotIn('id', DB::table('consumer_inbox')->select('domain_event_id'))
            ->count();
    }

    private function failedOutboxCount(): int
    {
        return (int) DB::table('transfer_outbox')->where('status', 'failed')->count();
    }
}
