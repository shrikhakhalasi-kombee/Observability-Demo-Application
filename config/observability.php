<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Configuration
    |--------------------------------------------------------------------------
    */
    'otel' => [
        // OTLP/gRPC endpoint for the trace exporter (Tempo or OTel Collector)
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://tempo:4317'),

        // Service name reported in all spans
        'service_name' => env('OTEL_SERVICE_NAME', 'laravel-observability-demo'),

        // Sampling ratio: 1.0 = 100%, 0.1 = 10%
        'sampler_arg' => (float) env('OTEL_TRACES_SAMPLER_ARG', 1.0),

        // Set to true to disable the SDK entirely (e.g. in unit tests)
        'disabled' => (bool) env('OTEL_SDK_DISABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Configuration
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        // Storage adapter: 'apcu' or 'redis' or 'in_memory'
        'storage' => env('METRICS_STORAGE', 'apcu'),

        // Namespace prefix for all metric names
        'namespace' => env('METRICS_NAMESPACE', ''),

        // Application version reported in app_info gauge
        'version' => env('APP_VERSION', '1.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anomaly Injection
    |--------------------------------------------------------------------------
    | All anomaly scenarios are grouped here and toggled via .env variables.
    | Zero overhead when disabled — every flag defaults to false.
    */
    'anomaly' => [
        // --- Artificial delay on order creation ---
        // Injects a random sleep between delay_min_ms and delay_max_ms
        // on routes matching delay_routes before the request is processed.
        'delay_enabled' => (bool) env('ANOMALY_DELAY_ENABLED', false),
        'delay_min_ms' => (int) env('ANOMALY_DELAY_MIN_MS', 1000),
        'delay_max_ms' => (int) env('ANOMALY_DELAY_MAX_MS', 3000),
        'delay_routes' => array_filter(
            explode(',', env('ANOMALY_DELAY_ROUTES', 'api/v1/orders'))
        ),

        // --- Inefficient query on order creation ---
        // Runs a full-table-scan + N+1 query inside the order.database_query span.
        'slow_query_enabled' => (bool) env('ANOMALY_SLOW_QUERY_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Query Logging Threshold
    |--------------------------------------------------------------------------
    | Queries exceeding this duration (in milliseconds) will emit a WARNING log.
    */
    'slow_query_threshold_ms' => (int) env('SLOW_QUERY_THRESHOLD_MS', 500),

];
