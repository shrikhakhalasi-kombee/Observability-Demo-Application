<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by the framework.
| The v1 group adds the /v1 prefix for versioning.
|
| Note: The /metrics endpoint is registered in routes/web.php so it is
| accessible at /metrics (without the /api prefix) for Prometheus scraping.
|
*/

// ── API v1 ────────────────────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {

    // ── Auth (unauthenticated) ────────────────────────────────────────────────
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // ── Authenticated routes ──────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Products
        Route::apiResource('products', ProductController::class);

        // Orders
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);

    });

});
