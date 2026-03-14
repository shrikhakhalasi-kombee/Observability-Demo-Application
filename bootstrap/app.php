<?php

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
            'trace' => \App\Http\Middleware\TraceMiddleware::class,
            'metrics' => \App\Http\Middleware\MetricsMiddleware::class,
            'request.log' => \App\Http\Middleware\RequestLogMiddleware::class,
            'anomaly.delay' => \App\Http\Middleware\AnomalyDelayMiddleware::class,
        ]);

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\TraceMiddleware::class,
            \App\Http\Middleware\AnomalyDelayMiddleware::class,
            \App\Http\Middleware\RequestLogMiddleware::class,
            \App\Http\Middleware\MetricsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
