<?php

use App\Http\Controllers\MetricsController;
use App\Http\Controllers\Web\AuthController;
use Illuminate\Support\Facades\Route;

// Prometheus scrape endpoint
Route::get('/metrics', [MetricsController::class, 'show']);

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Protected web UI
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('web.products'));
    Route::get('/products', fn () => view('products.index'))->name('web.products');
    Route::get('/orders', fn () => view('orders.index'))->name('web.orders');
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
});
