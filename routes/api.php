<?php

use App\Http\Controllers\Api\Codex\CodexReadController;
use App\Http\Controllers\Api\Codex\CodexWriteController;
use Illuminate\Support\Facades\Route;

Route::prefix('codex/v1')
    ->middleware(['codex.token', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/stores', [CodexReadController::class, 'stores']);
        Route::get('/stores/{store}/dashboard', [CodexReadController::class, 'dashboard']);
        Route::get('/stores/{store}/orders', [CodexReadController::class, 'orders']);
        Route::get('/stores/{store}/operations', [CodexReadController::class, 'operations']);
        Route::get('/stores/{store}/configuration-status', [CodexReadController::class, 'configurationStatus']);
        Route::get('/system/status', [CodexReadController::class, 'systemStatus']);

        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('/stores/{store}/actions/prepare/analytics-refresh', [CodexWriteController::class, 'prepareAnalyticsRefresh']);
            Route::post('/stores/{store}/actions/prepare/sync', [CodexWriteController::class, 'prepareSync']);
            Route::post('/stores/{store}/actions/prepare/sync-retry', [CodexWriteController::class, 'prepareSyncRetry']);
            Route::post('/stores/{store}/actions/prepare/store-notifications', [CodexWriteController::class, 'prepareStoreNotifications']);
            Route::post('/stores/{store}/actions/prepare/student-discount-status', [CodexWriteController::class, 'prepareStudentDiscountStatus']);
            Route::post('/actions/{confirmation}/execute', [CodexWriteController::class, 'execute']);
        });
    });
