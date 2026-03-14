<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * MetricsMiddleware
 *
 * - Increments app_active_requests gauge on request entry
 * - Decrements gauge and observes http_request_duration_seconds on response
 */
class MetricsMiddleware
{
    public function __construct(private readonly CollectorRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $namespace = config('observability.metrics.namespace', '');
        $startTime = microtime(true);

        // Increment active requests gauge
        try {
            $this->registry->getOrRegisterGauge(
                $namespace,
                'app_active_requests',
                'Number of requests currently being processed',
                []
            )->inc();
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        $response = $next($request);

        // Decrement active requests and record duration
        try {
            $duration = microtime(true) - $startTime;

            $this->registry->getOrRegisterGauge(
                $namespace,
                'app_active_requests',
                'Number of requests currently being processed',
                []
            )->dec();

            $route = $request->route()?->getName() ?? $request->path();

            $this->registry->getOrRegisterHistogram(
                $namespace,
                'http_request_duration_seconds',
                'Duration of HTTP requests in seconds',
                ['method', 'route', 'status_code'],
                [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
            )->observe($duration, [
                strtolower($request->method()),
                $route,
                (string) $response->getStatusCode(),
            ]);
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        return $response;
    }
}
