<?php

use App\Http\Middleware\AnomalyDelayMiddleware;
use App\Http\Middleware\MetricsMiddleware;
use App\Http\Middleware\RequestLogMiddleware;
use App\Http\Middleware\TraceMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'trace' => TraceMiddleware::class,
            'metrics' => MetricsMiddleware::class,
            'request.log' => RequestLogMiddleware::class,
            'anomaly.delay' => AnomalyDelayMiddleware::class,
        ]);

        $middleware->appendToGroup('api', [
            TraceMiddleware::class,
            AnomalyDelayMiddleware::class,
            RequestLogMiddleware::class,
            MetricsMiddleware::class,
        ]);

        $middleware->appendToGroup('web', [
            TraceMiddleware::class,
            RequestLogMiddleware::class,
            MetricsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
