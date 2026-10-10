<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach a W3C-compatible trace ID to every request.
 *
 * - Reads inbound traceparent / X-Trace-Id headers (propagation).
 * - Injects trace_id into the structured log context for every log line in this request.
 * - Echoes X-Trace-Id on the response so callers can correlate logs.
 */
final class AttachTraceId
{
    public function __construct(private TraceContext $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->initFromRequest($request);

        Log::withContext($this->trace->logContext());

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Trace-Id', $this->trace->traceId());

        return $response;
    }
}
