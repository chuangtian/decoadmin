<?php

use DecoMarketing\Controllers\AppController;
use DecoMarketing\Controllers\PublicController;
use DecoMarketing\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'organization.access', 'store.context'])->get('/marketing', [WorkspaceController::class, 'entry']);
Route::prefix('/organizations/{organization}/stores/{store}/marketing')->middleware(['web', 'auth', 'verified', 'organization.access', 'store.access'])->group(function () {
    Route::get('/', [WorkspaceController::class, 'index'])->name('marketing.index');
    Route::get('/export-contacts', [WorkspaceController::class, 'export'])->middleware('throttle:5,1');
    Route::post('/import-preview', [WorkspaceController::class, 'previewImport'])->middleware('throttle:20,1');
    Route::post('/actions/{action}', [WorkspaceController::class, 'change'])->middleware('throttle:30,1');
});
Route::match(['get', 'post'], '/marketing-public/unsubscribe/{contact}', [PublicController::class, 'unsubscribe'])->middleware(['signed', 'throttle:30,1'])->name('marketing.unsubscribe');
Route::get('/marketing-public/click/{delivery}', [PublicController::class, 'click'])->middleware(['signed', 'throttle:120,1'])->name('marketing.click');
Route::prefix('/api/shopify-app/marketing/proxy')->middleware(['shopify.app-proxy:marketing,marketing_store', 'throttle:60,1'])->group(function () {
    Route::get('/popup', [PublicController::class, 'popup']);
    Route::post('/subscribe', [PublicController::class, 'subscribe']);
    Route::post('/event', [PublicController::class, 'event']);
});

Route::get('/shopify-app/marketing', [AppController::class, 'home']);
Route::post('/api/shopify-app/marketing/bootstrap', [AppController::class, 'bootstrap'])->middleware(['shopify.id-token:marketing', 'throttle:10,1']);
Route::post('/api/shopify-app/marketing/webhooks', [AppController::class, 'webhook'])->middleware('throttle:240,1');
Route::post('/api/shopify-app/marketing/resend', [AppController::class, 'resend'])->middleware('throttle:240,1');

Route::get('/marketing-public/open/{delivery}', [PublicController::class, 'opened'])->middleware(['signed', 'throttle:120,1'])->name('marketing.open');
