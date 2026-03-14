<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\TracerInterface;
use Prometheus\CollectorRegistry;

class ProductService
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly CollectorRegistry $registry,
    ) {}

    /**
     * Return a paginated, optionally filtered list of products.
     *
     * Supported filters:
     *   search    – case-insensitive LIKE on name or description
     *   min_price – lower price bound (inclusive)
     *   max_price – upper price bound (inclusive)
     */
    public function list(array $filters, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        // Cap per_page at 100 (Requirement 5.5)
        $perPage = min($perPage, 100);

        $query = Product::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('description', 'LIKE', $term);
            });
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        $result = $query->paginate(perPage: $perPage, page: $page);

        // ── Anomaly: inefficient full-table scan (Requirement 11.1–11.5) ──────
        if (config('observability.slow_query_enabled', false)) {
            $this->executeSlowQueryAnomaly();
        }

        return $result;
    }

    /**
     * Execute the intentionally inefficient query wrapped in an OTel child span.
     * Records duration in Prometheus and emits a WARNING log if > 500 ms.
     *
     * Requirements: 11.1–11.5
     */
    private function executeSlowQueryAnomaly(): void
    {
        $sql = 'SELECT * FROM orders WHERE YEAR(created_at) = YEAR(NOW())';
        $namespace = config('observability.metrics.namespace', '');

        $span = $this->tracer
            ->spanBuilder('db.slow_query')
            ->setAttribute('db.statement', $sql)
            ->startSpan();

        $start = microtime(true);

        try {
            DB::select($sql);
        } catch (\Throwable) {
            // Never let the anomaly query crash the product list response
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $durationSeconds = $durationMs / 1000.0;

        $span->end();

        // Record in app_db_query_duration_seconds{query_type="select"}
        try {
            $this->registry->getOrRegisterHistogram(
                $namespace,
                'app_db_query_duration_seconds',
                'Duration of database queries in seconds',
                ['query_type'],
                [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5]
            )->observe($durationSeconds, ['select']);
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        // Emit WARNING if duration exceeds 500 ms (Requirement 11.4)
        if ($durationMs > 500) {
            Log::warning('Slow query executed', [
                'anomaly' => 'slow_query',
                'duration_ms' => (int) round($durationMs),
            ]);
        }
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function show(int $id): Product
    {
        return Product::findOrFail($id);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->fresh();
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }
}
