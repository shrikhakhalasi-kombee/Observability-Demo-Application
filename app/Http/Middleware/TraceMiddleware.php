<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * TraceMiddleware — Task 4.3
 *
 * Responsibilities:
 *  - Start OTel root span with http.method, http.route, http.url
 *  - Inject trace_id and span_id into Log::shareContext()
 *  - Set http.status_code on span after response
 *  - Set span status ERROR on exception
 */
class TraceMiddleware
{
    public function __construct(private readonly TracerInterface $tracer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $span = null;

        try {
            $span = $this->tracer->spanBuilder('http.request')
                ->setAttribute('http.method', $request->method())
                ->setAttribute('http.route', $request->path())
                ->setAttribute('http.url', $request->fullUrl())
                ->startSpan();

            $spanContext = $span->getContext();
            $traceId = $spanContext->getTraceId();
            $spanId = $spanContext->getSpanId();

            // Inject trace/span IDs into every log entry for this request
            Log::shareContext([
                'trace_id' => $traceId,
                'span_id' => $spanId,
            ]);
        } catch (\Throwable) {
            // Never let tracing setup crash the application
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Record exception on span and re-throw
            if ($span !== null) {
                try {
                    $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
                    $span->recordException($e);
                } catch (\Throwable) {
                    // Ignore tracing errors
                }
            }
            throw $e;
        } finally {
            // End span after response (or exception)
            if ($span !== null) {
                try {
                    $statusCode = isset($response) ? $response->getStatusCode() : 500;
                    $span->setAttribute('http.status_code', $statusCode);

                    if ($statusCode >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $span->end();
                } catch (\Throwable) {
                    // Ignore tracing errors
                }
            }
        }

        return $response;
    }
}
