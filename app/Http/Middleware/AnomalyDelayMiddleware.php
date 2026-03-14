<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use Symfony\Component\HttpFoundation\Response;

/**
 * AnomalyDelayMiddleware
 *
 * When ANOMALY_DELAY_ENABLED=true, injects a random delay between
 * ANOMALY_DELAY_MIN_MS and ANOMALY_DELAY_MAX_MS on matching routes.
 * Annotates the active OTel span and emits a WARNING log so the spike
 * is visible in Prometheus P95/P99, Tempo traces, and Loki.
 */
class AnomalyDelayMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.anomaly.delay_enabled', false)) {
            return $next($request);
        }

        $routes = (array) config('observability.anomaly.delay_routes', ['api/v1/orders']);

        if ($request->is(...$routes)) {
            $minMs = (int) config('observability.anomaly.delay_min_ms', 1000);
            $maxMs = (int) config('observability.anomaly.delay_max_ms', 3000);
            $delayMs = random_int($minMs, $maxMs);

            usleep($delayMs * 1000);

            // Annotate the active OTel span so the spike is visible in Tempo
            $span = Span::getCurrent();
            $span->setAttribute('anomaly.type', 'artificial_delay');
            $span->setAttribute('anomaly.delay_ms', $delayMs);
            $span->setAttribute('anomaly.route', $request->path());

            Log::warning('anomaly.delay_injected', [
                'delay_ms' => $delayMs,
                'route' => $request->path(),
                'method' => $request->method(),
            ]);
        }

        return $next($request);
    }
}
