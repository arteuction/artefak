<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consignment;
use App\Models\DomainEvent;
use App\Models\DonorFiscalYear;
use App\Models\Reserve;
use Carbon\Carbon;
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

    /**
     * Per-consumer lag metrics.
     *
     * Returns one row per known consumer with:
     *   consumer:          logical consumer name
     *   processed:         total inbox rows for this consumer
     *   last_processed_at: timestamp of most recent processed event
     *   lag_events:        domain events not yet seen by this consumer
     *   lag_seconds:       seconds since oldest unprocessed event was emitted
     *                      (null when no lag exists)
     */
    public function consumerLag(): JsonResponse
    {
        // All known consumers from the inbox
        $consumers = DB::table('consumer_inbox')
            ->select('consumer')
            ->distinct()
            ->orderBy('consumer')
            ->pluck('consumer');

        $totalEvents = (int) DB::table('domain_events')->count();
        $oldestEventAt = DB::table('domain_events')->min('created_at');

        $metrics = $consumers->map(function (string $consumer) use ($totalEvents, $oldestEventAt): array {
            $processed = (int) DB::table('consumer_inbox')
                ->where('consumer', $consumer)
                ->count();

            $lastProcessedAt = DB::table('consumer_inbox')
                ->where('consumer', $consumer)
                ->max('processed_at');

            $lagEvents = $totalEvents - $processed;

            // Seconds since the oldest domain event the consumer has not yet seen
            $lagSeconds = null;
            if ($lagEvents > 0 && $oldestEventAt !== null) {
                // Find oldest unprocessed event for this consumer
                $oldestUnprocessed = DB::table('domain_events')
                    ->whereNotIn('id', DB::table('consumer_inbox')
                        ->where('consumer', $consumer)
                        ->select('domain_event_id'))
                    ->min('created_at');

                if ($oldestUnprocessed !== null) {
                    $lagSeconds = (int) now()->diffInSeconds(\Carbon\Carbon::parse($oldestUnprocessed));
                }
            }

            return [
                'consumer'          => $consumer,
                'processed'         => $processed,
                'last_processed_at' => $lastProcessedAt,
                'lag_events'        => $lagEvents,
                'lag_seconds'       => $lagSeconds,
            ];
        });

        return response()->json(['data' => $metrics]);
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

    /**
     * ЗКПО чл.31 tax report — deductible donation summary for a fiscal year.
     *
     * GET /api/v1/ops/zkpo-report/{year?}
     *
     * Returns:
     *   fiscal_year                  — the year being reported
     *   total_donations_cents        — sum of all confirmed donations that year
     *   total_deductible_cents       — max deductible amount (10% rule per donor, 65% rule for corp)
     *   donors_with_documentation    — donors who submitted tax documentation
     *   eligible_basis_breakdown     — count by eligibility_basis (individual | corporate | ngo)
     *   per_project                  — donation totals per ImpactProject
     */
    public function zkpoReport(int $year = 0): JsonResponse
    {
        if ($year === 0) {
            $year = (int) now()->format('Y');
        }

        $totals = DB::table('donor_fiscal_years')
            ->where('fiscal_year', $year)
            ->selectRaw('
                SUM(aggregate_donated_cents)        AS total_donated_cents,
                SUM(aggregate_max_deductible_cents) AS total_deductible_cents,
                SUM(donation_count)                 AS total_donations,
                COUNT(DISTINCT donor_id)            AS total_donors
            ')
            ->first();

        $basisBreakdown = DB::table('donor_fiscal_years')
            ->where('fiscal_year', $year)
            ->selectRaw('eligibility_basis, COUNT(*) AS cnt, SUM(aggregate_donated_cents) AS donated_cents')
            ->groupBy('eligibility_basis')
            ->get();

        $docStatus = DB::table('donor_fiscal_years')
            ->where('fiscal_year', $year)
            ->selectRaw('documentation_status, COUNT(*) AS cnt')
            ->groupBy('documentation_status')
            ->pluck('cnt', 'documentation_status');

        $perProject = DB::table('donations')
            ->join('impact_projects', 'impact_projects.id', '=', 'donations.impact_project_id')
            ->whereYear('donations.donated_at', $year)
            ->where('donations.status', 'confirmed')
            ->whereNotNull('donations.impact_project_id')
            ->selectRaw('
                impact_projects.id,
                impact_projects.title,
                impact_projects.slug,
                SUM(donations.donated_cents) AS total_donated_cents,
                COUNT(*)                     AS donation_count
            ')
            ->groupBy('impact_projects.id', 'impact_projects.title', 'impact_projects.slug')
            ->orderByDesc('total_donated_cents')
            ->get();

        return response()->json([
            'fiscal_year'               => $year,
            'total_donated_cents'       => (int) ($totals->total_donated_cents ?? 0),
            'total_deductible_cents'    => (int) ($totals->total_deductible_cents ?? 0),
            'total_donations'           => (int) ($totals->total_donations ?? 0),
            'total_donors'              => (int) ($totals->total_donors ?? 0),
            'documentation_status'      => $docStatus,
            'eligibility_basis'         => $basisBreakdown,
            'per_project'               => $perProject,
        ]);
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
