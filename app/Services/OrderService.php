<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Prometheus\CollectorRegistry;

class OrderService
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly TracerInterface $tracer,
    ) {}

    /**
     * Create an order inside a DB transaction.
     *
     * Locks each product row for update, validates stock, decrements stock,
     * creates Order + OrderItem records, and returns the Order with items loaded.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws ValidationException when a product has insufficient stock
     * @throws \Throwable on any other failure (transaction is rolled back)
     */
    public function create(array $items, int $userId): Order
    {
        // Span 2: Business logic
        $bizSpan = $this->tracer->spanBuilder('order.business_logic')->startSpan();
        $bizScope = $bizSpan->activate();

        try {
            // Span 3: Database query
            $dbSpan = $this->tracer->spanBuilder('order.database_query')->startSpan();
            $dbScope = $dbSpan->activate();

            // Anomaly: inefficient full-table-scan query to simulate DB slowdown
            if (config('observability.anomaly.slow_query_enabled', false)) {
                $this->injectSlowQuery($dbSpan);
            }

            $order = DB::transaction(function () use ($items, $userId) {
                $totalPrice = '0.00';
                $orderItems = [];

                foreach ($items as $item) {
                    /** @var Product $product */
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                    if ($item['quantity'] > $product->stock) {
                        throw ValidationException::withMessages([
                            'items' => "Insufficient stock for product ID {$product->id}. "
                                ."Requested: {$item['quantity']}, available: {$product->stock}.",
                        ]);
                    }

                    $product->decrement('stock', $item['quantity']);

                    $unitPrice = $product->price;
                    $totalPrice = bcadd($totalPrice, bcmul((string) $item['quantity'], (string) $unitPrice, 4), 2);

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                    ];
                }

                $order = Order::create([
                    'user_id' => $userId,
                    'status' => 'pending',
                    'total_price' => $totalPrice,
                ]);

                $order->orderItems()->createMany($orderItems);

                return $order->load('orderItems');
            });

            $dbSpan->setAttribute('db.order_id', $order->id);
            $dbSpan->setAttribute('db.item_count', count($items));
            $dbSpan->end();
            $dbScope->detach();
        } catch (\Throwable $e) {
            $dbSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $dbSpan->end();
            $dbScope->detach();
            $bizSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $bizSpan->end();
            $bizScope->detach();
            throw $e;
        }

        $bizSpan->setAttribute('order.total_price', (float) $order->total_price);
        $bizSpan->end();
        $bizScope->detach();

        // Record metrics after successful order creation
        try {
            $namespace = config('observability.metrics.namespace', '');

            $this->registry->getOrRegisterCounter(
                $namespace,
                'orders_created_total',
                'Total number of orders created',
                []
            )->inc();

            $this->registry->getOrRegisterHistogram(
                $namespace,
                'app_order_value_dollars',
                'Order monetary value in dollars',
                [],
                [1, 5, 10, 25, 50, 100, 250, 500, 1000]
            )->observe((float) $order->total_price);
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        return $order;
    }

    /**
     * Anomaly: run an intentionally inefficient query (full table scan with
     * repeated subqueries) to produce a measurable DB slowdown.
     * Annotates the given OTel span and emits a WARNING log.
     */
    private function injectSlowQuery(SpanInterface $span): void
    {
        $start = microtime(true);

        // Aggregate pass — full table scan, defeats index on price
        $count = DB::table('products')
            ->select(DB::raw('COUNT(*) as cnt, SUM(price) as total, AVG(price) as avg_price'))
            ->whereRaw('1=1')
            ->first();

        // N+1 pass — fetch each product row individually
        $ids = DB::table('products')->pluck('id');
        foreach ($ids as $id) {
            DB::table('products')->where('id', $id)->value('price');
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $span->setAttribute('anomaly.type', 'slow_query');
        $span->setAttribute('anomaly.slow_query_duration_ms', $durationMs);
        $span->setAttribute('anomaly.rows_scanned', $count?->cnt ?? 0);

        Log::warning('anomaly.slow_query_injected', [
            'duration_ms' => $durationMs,
            'rows_scanned' => $count?->cnt ?? 0,
            'total_price' => $count?->total ?? 0,
        ]);
    }

    /**
     * Return a paginated list of orders for the given user, with optional filters.
     *
     * Supported filters:
     *   status       – exact match on order status
     *   created_from – lower bound on created_at (inclusive)
     *   created_to   – upper bound on created_at (inclusive)
     */
    public function list(int $userId, array $filters, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min($perPage, 100);

        $query = Order::with('orderItems')->where('user_id', $userId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
