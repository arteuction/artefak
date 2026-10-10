<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Str;

/**
 * Per-request trace context.
 *
 * Accepts an inbound W3C traceparent or X-Trace-Id header and propagates it
 * through structured log context. Falls back to a freshly generated UUID v4.
 */
final class TraceContext
{
    private string $traceId;
    private ?string $spanId;

    public function __construct()
    {
        $this->traceId = Str::uuid()->toString();
        $this->spanId  = null;
    }

    public function initFromRequest(\Illuminate\Http\Request $request): void
    {
        // W3C Trace Context: traceparent: 00-{traceId}-{parentId}-{flags}
        $traceparent = $request->header('traceparent');
        if ($traceparent && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-/', $traceparent, $m)) {
            $this->traceId = $m[1];
            $this->spanId  = $m[2];
            return;
        }

        // Simpler X-Trace-Id header (internal or Stripe)
        $xTrace = $request->header('X-Trace-Id');
        if ($xTrace) {
            $this->traceId = $xTrace;
        }
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    /** Return array suitable for Log::withContext() */
    public function logContext(): array
    {
        $ctx = ['trace_id' => $this->traceId];
        if ($this->spanId !== null) {
            $ctx['span_id'] = $this->spanId;
        }
        return $ctx;
    }
}
