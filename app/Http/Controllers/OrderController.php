<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly TracerInterface $tracer,
    ) {}

    /**
     * GET /api/v1/orders
     * Paginated list of the authenticated user's orders with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'created_from', 'created_to']);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        $paginator = $this->orderService->list(
            userId: $request->user()->id,
            filters: $filters,
            page: $page,
            perPage: $perPage,
        );

        return response()->json([
            'data' => OrderResource::collection($paginator->items()),
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
     * POST /api/v1/orders
     * Create a new order with transactional stock management.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        // Span 1: Controller
        $controllerSpan = $this->tracer->spanBuilder('order.controller')->startSpan();
        $controllerScope = $controllerSpan->activate();

        try {
            $order = $this->orderService->create(
                items: $request->validated('items'),
                userId: $request->user()->id,
            );
        } catch (ValidationException $e) {
            $controllerSpan->setStatus(StatusCode::STATUS_ERROR, 'Validation failed');
            $controllerSpan->end();
            $controllerScope->detach();

            return response()->json(['errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            $controllerSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $controllerSpan->end();
            $controllerScope->detach();

            return response()->json(['message' => 'Order could not be created due to a server error.'], 500);
        }

        // Span 4: Response formatting
        $responseSpan = $this->tracer->spanBuilder('order.response_formatting')->startSpan();
        $responseScope = $responseSpan->activate();
        $resource = new OrderResource($order);
        $responseSpan->end();
        $responseScope->detach();

        // Log trace ID for log/trace correlation
        $traceId = $controllerSpan->getContext()->getTraceId();
        Log::info('order.created', [
            'order_id' => $order->id,
            'trace_id' => $traceId,
            'total_price' => $order->total_price,
        ]);

        $controllerSpan->end();
        $controllerScope->detach();

        return response()->json(['data' => $resource], 201);
    }
}
