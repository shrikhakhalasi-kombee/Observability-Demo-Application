<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;

/**
 * ObservabilityServiceProvider
 *
 * Bootstraps all observability concerns:
 *  - Prometheus metrics registry (Task 4.1)
 *  - OpenTelemetry tracer (Task 4.3)
 *  - DB query listener for metrics + slow-query logging (Tasks 4.1, 4.2)
 *  - Environment validation (Task 9.3)
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/observability.php',
            'observability'
        );

        // Bind CollectorRegistry as a singleton so all parts of the request
        // lifecycle share the same registry instance.
        $this->app->singleton(CollectorRegistry::class, function () {
            try {
                if (! extension_loaded('apcu') || ! apcu_enabled()) {
                    throw new \RuntimeException('APCu not available');
                }
                $storage = new APC;
            } catch (\Throwable) {
                $storage = new InMemory;
            }

            return new CollectorRegistry($storage, false);
        });

        // Bind the OTel TracerInterface as a singleton
        $this->app->singleton(TracerInterface::class, function () {
            return $this->buildTracer();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateEnvironment();
        $this->registerMetrics();
        $this->registerDbListener();
    }

    /**
     * Validate required environment variables are present.
     * Logs an error and throws RuntimeException for any missing required var.
     * Logs INFO with the current anomaly flag states on successful validation.
     *
     * Task 9.3
     */
    private function validateEnvironment(): void
    {
        $required = [
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'OTEL_EXPORTER_OTLP_ENDPOINT',
        ];

        foreach ($required as $name) {
            if (env($name) === null) {
                Log::error('Missing required environment variable', ['var' => $name]);
                throw new \RuntimeException("Missing required environment variable: {$name}");
            }
        }

        // Optional vars with their documented defaults
        $optional = [
            'ANOMALY_DELAY_ENABLED' => 'false',
            'ANOMALY_DELAY_MS' => '2000',
            'ANOMALY_SLOW_QUERY_ENABLED' => 'false',
            'OTEL_TRACES_SAMPLER_ARG' => '1.0',
        ];

        $anomalyFlags = [];
        foreach ($optional as $name => $default) {
            $anomalyFlags[$name] = env($name, $default);
        }

        Log::info('Observability boot: environment validated', [
            'ANOMALY_DELAY_ENABLED' => $anomalyFlags['ANOMALY_DELAY_ENABLED'],
            'ANOMALY_DELAY_MS' => $anomalyFlags['ANOMALY_DELAY_MS'],
            'ANOMALY_SLOW_QUERY_ENABLED' => $anomalyFlags['ANOMALY_SLOW_QUERY_ENABLED'],
            'OTEL_TRACES_SAMPLER_ARG' => $anomalyFlags['OTEL_TRACES_SAMPLER_ARG'],
        ]);
    }

    /**
     * Build and return the OTel Tracer, or a noop tracer on failure.
     */
    private function buildTracer(): TracerInterface
    {
        if (config('observability.otel.disabled', false)) {
            return NoopTracer::getInstance();
        }

        try {
            $endpoint = config('observability.otel.endpoint', 'http://tempo:4317');
            // Strip http:// or https:// prefix — gRPC transport expects host:port
            $grpcEndpoint = preg_replace('#^https?://#', '', $endpoint);

            // Build the gRPC transport pointing at the OTLP endpoint
            $transport = (new GrpcTransportFactory)->create(
                'http://'.$grpcEndpoint.'/opentelemetry.proto.collector.trace.v1.TraceService/Export'
            );

            $exporter = new SpanExporter($transport);

            $samplerArg = (float) config('observability.otel.sampler_arg', 1.0);
            $sampler = new ParentBased(new TraceIdRatioBasedSampler($samplerArg));

            $serviceName = config('observability.otel.service_name', 'laravel-observability-demo');
            $resource = ResourceInfo::create(
                Attributes::create([ResourceAttributes::SERVICE_NAME => $serviceName])
            );

            $processor = new BatchSpanProcessor($exporter, Clock::getDefault());

            $tracerProvider = new TracerProvider(
                spanProcessors: [$processor],
                sampler: $sampler,
                resource: $resource,
            );

            return $tracerProvider->getTracer($serviceName);
        } catch (\Throwable $e) {
            Log::warning('OTel tracer initialisation failed — tracing disabled', [
                'error' => $e->getMessage(),
            ]);

            return NoopTracer::getInstance();
        }
    }

    /**
     * Register all 10 Prometheus metrics.
     */
    private function registerMetrics(): void
    {
        /** @var CollectorRegistry $registry */
        $registry = $this->app->make(CollectorRegistry::class);

        $namespace = config('observability.metrics.namespace', '');

        // ── Histograms ────────────────────────────────────────────────────────

        $registry->getOrRegisterHistogram(
            $namespace,
            'http_request_duration_seconds',
            'Duration of HTTP requests in seconds',
            ['method', 'route', 'status_code'],
            [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );

        $registry->getOrRegisterHistogram(
            $namespace,
            'app_order_value_dollars',
            'Order monetary value in dollars',
            [],
            [1, 5, 10, 25, 50, 100, 250, 500, 1000]
        );

        $registry->getOrRegisterHistogram(
            $namespace,
            'app_db_query_duration_seconds',
            'Duration of database queries in seconds',
            ['query_type'],
            [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5]
        );

        // ── Gauges ────────────────────────────────────────────────────────────

        $registry->getOrRegisterGauge(
            $namespace,
            'app_active_requests',
            'Number of requests currently being processed',
            []
        );

        $appInfo = $registry->getOrRegisterGauge(
            $namespace,
            'app_info',
            'Static application metadata',
            ['version', 'environment']
        );

        // Set app_info to 1 immediately on boot
        $appInfo->set(1, [
            config('observability.metrics.version', '1.0.0'),
            config('app.env', 'local'),
        ]);

        // ── Counters ──────────────────────────────────────────────────────────

        $registry->getOrRegisterCounter(
            $namespace,
            'app_user_registrations_total',
            'Total number of successful user registrations',
            []
        );

        $registry->getOrRegisterCounter(
            $namespace,
            'app_user_logins_total',
            'Total number of successful user logins',
            []
        );

        $registry->getOrRegisterCounter(
            $namespace,
            'app_product_requests_total',
            'Total number of product endpoint calls',
            ['operation', 'status']
        );

        $registry->getOrRegisterCounter(
            $namespace,
            'app_orders_created_total',
            'Total number of orders created',
            []
        );

        $registry->getOrRegisterCounter(
            $namespace,
            'app_db_queries_total',
            'Total number of database queries executed',
            ['query_type']
        );
    }

    /**
     * Register the DB::listen callback to track query metrics, slow query logging,
     * and OTel child spans for each query.
     */
    private function registerDbListener(): void
    {
        /** @var CollectorRegistry $registry */
        $registry = $this->app->make(CollectorRegistry::class);
        $namespace = config('observability.metrics.namespace', '');

        DB::listen(function ($query) use ($registry, $namespace) {
            $queryType = $this->parseQueryType($query->sql);
            $durationSeconds = $query->time / 1000.0;

            // ── Prometheus metrics ────────────────────────────────────────────
            try {
                $registry->getOrRegisterCounter(
                    $namespace,
                    'app_db_queries_total',
                    'Total number of database queries executed',
                    ['query_type']
                )->inc([$queryType]);

                $registry->getOrRegisterHistogram(
                    $namespace,
                    'app_db_query_duration_seconds',
                    'Duration of database queries in seconds',
                    ['query_type'],
                    [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5]
                )->observe($durationSeconds, [$queryType]);
            } catch (\Throwable) {
                // Never let metrics recording crash the application
            }

            // ── OTel child span ───────────────────────────────────────────────
            try {
                /** @var TracerInterface $tracer */
                $tracer = $this->app->make(TracerInterface::class);
                $span = $tracer->spanBuilder('db.query')
                    ->setAttribute('db.statement', $query->sql)
                    ->setAttribute('db.system', 'mysql')
                    ->setAttribute('db.duration_ms', (int) round($query->time))
                    ->startSpan();
                $span->end();
            } catch (\Throwable) {
                // Never let tracing crash the application
            }

            // ── Slow query logging — Requirement 8.4 ─────────────────────────
            if ($query->time > 500) {
                $traceId = '';
                try {
                    $sharedContext = Log::sharedContext();
                    $traceId = $sharedContext['trace_id'] ?? '';
                } catch (\Throwable) {
                    // Ignore if shared context is unavailable
                }

                Log::warning('Slow database query detected', [
                    'query' => $query->sql,
                    'bindings' => $query->bindings,
                    'duration_ms' => (int) round($query->time),
                    'trace_id' => $traceId,
                ]);
            }
        });
    }

    /**
     * Parse the query type (select/insert/update/delete/other) from raw SQL.
     */
    private function parseQueryType(string $sql): string
    {
        $first = strtolower(strtok(ltrim($sql), " \t\n\r"));

        return match ($first) {
            'select' => 'select',
            'insert' => 'insert',
            'update' => 'update',
            'delete' => 'delete',
            default => 'other',
        };
    }
}
