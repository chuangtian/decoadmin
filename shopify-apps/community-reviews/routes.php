<?php

use CommunityReviews\Controllers\AppController;
use CommunityReviews\Controllers\ManagementController;
use CommunityReviews\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/shopify-app/community-reviews', [AppController::class, 'home'])->name('community-reviews.app');
Route::post('/api/shopify-app/community-reviews/bootstrap', [AppController::class, 'bootstrap'])
    ->middleware(['shopify.id-token:community_reviews', 'throttle:20,1']);
Route::post('/api/shopify-app/community-reviews/webhooks', [AppController::class, 'webhook'])->middleware('throttle:120,1');
Route::prefix('/api/shopify-app/community-reviews/proxy')
    ->middleware(['shopify.app-proxy:community_reviews,community_reviews_store', 'throttle:240,1'])
    ->group(function (): void {
        Route::get('/feed', [StorefrontController::class, 'feed']);
        Route::get('/images/{image}', [StorefrontController::class, 'image'])->whereUuid('image');
    });
Route::prefix('/organizations/{organization}/stores/{store}/community-reviews')
    ->middleware(['web', 'auth', 'verified', 'organization.access', 'store.access', 'permission:apps.view', 'permission:products.view', 'permission:reports.view'])
    ->group(function (): void {
        Route::get('/', [ManagementController::class, 'index'])->name('community-reviews.index');
        Route::put('/', [ManagementController::class, 'save'])->middleware(['permission:products.update', 'throttle:30,1'])->name('community-reviews.save');
        Route::get('/products', [ManagementController::class, 'products'])->middleware('throttle:60,1');
        Route::get('/preview', [ManagementController::class, 'preview'])->middleware('throttle:30,1');
        Route::get('/images/{image}', [ManagementController::class, 'image'])->whereUuid('image')->name('community-reviews.preview-image');
    });
