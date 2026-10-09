<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/health
 *
 * Returns the operational status of the application's critical dependencies.
 * Used by load balancers, uptime monitors, and deploy pipelines.
 *
 * Response shape:
 *   {
 *     "status": "ok" | "degraded",
 *     "checks": {
 *       "database": "ok" | "error",
 *       "queue":    "ok" | "unknown"
 *     },
 *     "version": "string"
 *   }
 *
 * HTTP 200 when status=ok, HTTP 503 when status=degraded.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        // Database connectivity
        try {
            DB::selectOne('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'error';
        }

        // Queue — we check whether the jobs table is reachable as a proxy.
        // A real job-based probe would require a separate worker check.
        try {
            DB::table('jobs')->count();
            $checks['queue'] = 'ok';
        } catch (\Throwable) {
            $checks['queue'] = 'unknown';
        }

        $degraded = in_array('error', $checks, true);

        return response()->json([
            'status'  => $degraded ? 'degraded' : 'ok',
            'checks'  => $checks,
            'version' => config('app.version', '1.0.0'),
        ], $degraded ? 503 : 200);
    }
}
