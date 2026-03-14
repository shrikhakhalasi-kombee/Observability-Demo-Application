<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\TracerInterface;
use Prometheus\CollectorRegistry;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly CollectorRegistry $registry,
        private readonly TracerInterface $tracer,
    ) {}

    /**
     * GET /api/v1/products
     * Paginated list with optional search and price-range filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'min_price', 'max_price']);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        $paginator = $this->productService->list($filters, $page, $perPage);

        $this->incrementProductCounter('list', 'success');

        // OTel named span — Requirement 3.9
        try {
            $span = $this->tracer->spanBuilder('product.list')->startSpan();
            $span->end();
        } catch (\Throwable) {
        }

        return response()->json([
            'data' => ProductResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    /**
     * POST /api/v1/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        $this->incrementProductCounter('create', 'success');

        // OTel named span — Requirement 3.9
        try {
            $span = $this->tracer->spanBuilder('product.create')
                ->setAttribute('product.id', $product->id)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
        }

        return response()->json(['data' => new ProductResource($product)], 201);
    }

    /**
     * GET /api/v1/products/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = $this->productService->show((int) $id);
        } catch (ModelNotFoundException) {
            $this->incrementProductCounter('show', 'error');

            return response()->json(['message' => 'Product not found.'], 404);
        }

        $this->incrementProductCounter('show', 'success');

        // OTel named span — Requirement 3.9
        try {
            $span = $this->tracer->spanBuilder('product.show')
                ->setAttribute('product.id', $product->id)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
        }

        return response()->json(['data' => new ProductResource($product)]);
    }

    /**
     * PUT /api/v1/products/{id}
     */
    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $product = $this->productService->show((int) $id);
        } catch (ModelNotFoundException) {
            $this->incrementProductCounter('update', 'error');

            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product = $this->productService->update($product, $request->validated());

        $this->incrementProductCounter('update', 'success');

        // OTel named span — Requirement 3.9
        try {
            $span = $this->tracer->spanBuilder('product.update')
                ->setAttribute('product.id', $product->id)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
        }

        return response()->json(['data' => new ProductResource($product)]);
    }

    /**
     * DELETE /api/v1/products/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $product = $this->productService->show((int) $id);
        } catch (ModelNotFoundException) {
            $this->incrementProductCounter('delete', 'error');

            return response()->json(['message' => 'Product not found.'], 404);
        }

        $this->productService->delete($product);

        $this->incrementProductCounter('delete', 'success');

        // OTel named span — Requirement 3.9
        try {
            $span = $this->tracer->spanBuilder('product.delete')
                ->setAttribute('product.id', $product->id)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
        }

        return response()->json(null, 204);
    }

    /**
     * Increment the app_product_requests_total counter.
     */
    private function incrementProductCounter(string $operation, string $status): void
    {
        try {
            $this->registry->getOrRegisterCounter(
                config('observability.metrics.namespace', ''),
                'app_product_requests_total',
                'Total number of product endpoint calls',
                ['operation', 'status']
            )->inc([$operation, $status]);
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }
    }
}
