<?php

use App\Http\Controllers\ClientCatalogController;
use App\Http\Controllers\NodeAutomationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SandboxPaymentController;
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
    Route::get('/clients', [ClientCatalogController::class, 'memberIndex']);
    Route::get('/orders', [OrderController::class, 'listMember']);
    Route::get('/orders/{id}', [OrderController::class, 'detail']);
    Route::post('/orders', [OrderController::class, 'create'])->middleware('throttle:20,1');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{id}/sandbox-payment', [SandboxPaymentController::class, 'simulate'])->middleware('throttle:10,1');
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
    Route::get('/node-deployments', [NodeAutomationController::class, 'index']);
    Route::post('/node-deployments', [NodeAutomationController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/node-deployments/{id}', [NodeAutomationController::class, 'show']);
    Route::post('/node-deployments/{id}/retire', [NodeAutomationController::class, 'retire'])->middleware('throttle:10,1');
    Route::get('/node-deployments/{id}/profile', [NodeAutomationController::class, 'profile'])->middleware('throttle:5,1');
    Route::get('/orders', [OrderController::class, 'listAdmin']);
    Route::get('/orders/{id}', [OrderController::class, 'detail']);
    Route::get('/summary', [PlatformController::class, 'summary']);
    Route::get('/users', [PlatformController::class, 'users']);
    Route::get('/nodes', [PlatformController::class, 'nodes']);
    Route::post('/nodes', [PlatformController::class, 'createNode']);
    Route::get('/nodes/{id}', [PlatformController::class, 'nodeDetail']);
    Route::patch('/nodes/{id}', [PlatformController::class, 'updateNode']);
    Route::get('/commissions', [PlatformController::class, 'commissions']);
    Route::get('/plans', [PlatformController::class, 'adminPlans']);
    Route::post('/plans', [PlatformController::class, 'createPlan']);
    Route::put('/plans/{family}', [PlatformController::class, 'updatePlan']);
    Route::patch('/plans/{family}/status', [PlatformController::class, 'updatePlanStatus']);
    Route::get('/clients', [ClientCatalogController::class, 'adminIndex']);
    Route::post('/clients', [ClientCatalogController::class, 'store']);
    Route::put('/clients/{id}', [ClientCatalogController::class, 'update']);
    Route::patch('/clients/{id}/status', [ClientCatalogController::class, 'updateStatus']);
    Route::get('/tickets', [TicketController::class, 'listAdmin']);
    Route::get('/tickets/{id}', [TicketController::class, 'detail']);
    Route::post('/tickets/{id}/replies', [TicketController::class, 'reply']);
    Route::patch('/tickets/{id}', [TicketController::class, 'updateStatus']);
});
