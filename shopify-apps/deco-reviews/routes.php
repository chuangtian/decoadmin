<?php

use DecoReviews\Controllers\AppController;
use DecoReviews\Controllers\FormController;
use DecoReviews\Controllers\ManagementController;
use DecoReviews\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/shopify-app/deco-reviews', [AppController::class, 'home']);
Route::post('/api/shopify-app/deco-reviews/bootstrap', [AppController::class, 'bootstrap'])->middleware(['shopify.id-token:deco_reviews', 'throttle:20,1']);
Route::post('/api/shopify-app/deco-reviews/webhooks', [AppController::class, 'webhook'])->middleware('throttle:120,1');

Route::prefix('/organizations/{organization}/stores/{store}/deco-reviews')
    ->middleware(['web', 'auth', 'verified', 'organization.access', 'store.access', 'permission:apps.view', 'permission:products.view'])
    ->group(function () {
        Route::get('/', [ManagementController::class, 'index'])->name('deco-reviews.index');
        Route::get('/preview', [ManagementController::class, 'preview'])->name('deco-reviews.preview');
        Route::get('/widget-preview', [ManagementController::class, 'widget'])->name('deco-reviews.widget-preview');
        Route::get('/export', [ManagementController::class, 'export'])->middleware('throttle:10,1');
        Route::get('/form-preview', [FormController::class, 'preview'])->name('deco-reviews.form-preview');
        Route::get('/email-preview', [ManagementController::class, 'emailPreview'])->name('deco-reviews.email-preview');
        Route::get('/media/{media}', [ManagementController::class, 'media'])->whereUuid('media')->name('deco-reviews.media');
        Route::middleware(['permission:products.update', 'throttle:30,1'])->group(function () {
            Route::post('/reviews', [ManagementController::class, 'create']);
            Route::post('/reviews/bulk', [ManagementController::class, 'bulk']);
            Route::patch('/reviews/{review}', [ManagementController::class, 'moderate'])->whereUuid('review');
            Route::put('/settings', [ManagementController::class, 'settings']);
            Route::post('/rewards/{reward}/reconcile', [ManagementController::class, 'reconcileReward'])->whereUuid('reward');
            Route::post('/reward-deliveries/{delivery}/reconcile', [ManagementController::class, 'reconcileRewardDelivery'])->whereUuid('delivery');
            Route::put('/form', [FormController::class, 'save']);
            Route::post('/invitations', [ManagementController::class, 'invitations']);
            Route::post('/invitations/{invitation}/cancel', [ManagementController::class, 'cancel'])->whereUuid('invitation');
            Route::post('/imports', [ManagementController::class, 'import']);
            Route::post('/imports/{batch}/undo', [ManagementController::class, 'undo'])->whereUuid('batch');
        });
    });

Route::get('/api/shopify-app/deco-reviews/proxy/feed', [StorefrontController::class, 'feed'])
    ->middleware(['shopify.app-proxy:deco_reviews,deco_reviews_store', 'throttle:120,1']);
Route::post('/api/shopify-app/deco-reviews/proxy/reviews', [StorefrontController::class, 'organic'])
    ->middleware(['shopify.app-proxy:deco_reviews,deco_reviews_store', 'throttle:60,1']);
Route::get('/api/shopify-app/deco-reviews/media/{media}', [StorefrontController::class, 'media'])->whereUuid('media')->middleware('throttle:240,1')->name('deco-reviews.public-media');
Route::get('/api/shopify-app/deco-reviews/assets/{asset}', [StorefrontController::class, 'asset']);
Route::middleware(['web', 'signed', 'throttle:20,1'])->group(function () {
    Route::get('/reviews/write/{invitation}', [StorefrontController::class, 'write'])->whereUuid('invitation')->name('deco-reviews.write');
    Route::post('/reviews/write/{invitation}', [StorefrontController::class, 'submit'])->whereUuid('invitation');
    Route::match(['get', 'post'], '/reviews/unsubscribe/{invitation}', [StorefrontController::class, 'unsubscribe'])->whereUuid('invitation')->name('deco-reviews.unsubscribe');
});
