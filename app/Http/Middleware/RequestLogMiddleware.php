<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequestLogMiddleware
 *
 * Wraps every request to capture timing and emit a structured JSON log entry:
 *   {"url": "/orders", "method": "POST", "status": 200, "duration_ms": 145}
 */
class RequestLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        Log::info('request', [
            'url' => $request->getPathInfo(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);

        return $response;
    }
}
