<?php

use App\Http\Controllers\PlatformController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', [PlatformController::class, 'health']);
Route::get('/api/v1/auth/csrf', [PlatformController::class, 'csrf']);
Route::get('/api/v1/auth/session', [PlatformController::class, 'session']);
Route::post('/api/v1/auth/login', [PlatformController::class, 'login'])->middleware('throttle:5,1');
Route::post('/api/v1/auth/logout', [PlatformController::class, 'logout']);
Route::get('/api/v1/catalog/plans', [PlatformController::class, 'catalog']);
Route::get('/subscribe/{token}', [PlatformController::class, 'subscriptionProfile'])->middleware('throttle:60,1');

Route::prefix('api/v1/me')->middleware('auth')->group(function (): void {
    Route::get('/overview', [PlatformController::class, 'overview']);
    Route::get('/orders', [PlatformController::class, 'orders']);
    Route::get('/subscriptions', [PlatformController::class, 'subscriptions']);
    Route::post('/subscriptions', [PlatformController::class, 'createSubscription']);
    Route::post('/subscriptions/{id}/rotate', [PlatformController::class, 'rotateSubscription']);
    Route::post('/subscriptions/{id}/revoke', [PlatformController::class, 'revokeSubscription']);
    Route::get('/tickets', [TicketController::class, 'listMember']);
    Route::get('/tickets/{id}', [TicketController::class, 'detail']);
    Route::post('/tickets', [TicketController::class, 'create']);
    Route::post('/tickets/{id}/replies', [TicketController::class, 'reply']);
});

Route::prefix('api/v1/admin')->middleware(['auth', 'admin'])->group(function (): void {
    Route::get('/summary', [PlatformController::class, 'summary']);
    Route::get('/users', [PlatformController::class, 'users']);
    Route::get('/nodes', [PlatformController::class, 'nodes']);
    Route::patch('/nodes/{id}', [PlatformController::class, 'updateNode']);
    Route::get('/commissions', [PlatformController::class, 'commissions']);
    Route::get('/plans', [PlatformController::class, 'adminPlans']);
    Route::post('/plans', [PlatformController::class, 'createPlan']);
    Route::put('/plans/{family}', [PlatformController::class, 'updatePlan']);
    Route::patch('/plans/{family}/status', [PlatformController::class, 'updatePlanStatus']);
    Route::get('/tickets', [TicketController::class, 'listAdmin']);
    Route::get('/tickets/{id}', [TicketController::class, 'detail']);
    Route::post('/tickets/{id}/replies', [TicketController::class, 'reply']);
    Route::patch('/tickets/{id}', [TicketController::class, 'updateStatus']);
});
