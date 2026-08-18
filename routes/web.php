<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShopifyConnectionDisconnectController;
use App\Http\Controllers\ShopifyConnectionHealthController;
use App\Http\Controllers\ShopifyDataController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StoreContextController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookEventController;
use App\Models\Store;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::get('/shopify/oauth/callback', [ShopifyOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('shopify.oauth.callback');

Route::post('/shopify/webhooks/{app:handle}', ShopifyWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('shopify.webhooks.receive');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

    Route::put('/context/organization', [OrganizationContextController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('context.organization.update');
    Route::put('/context/store', [StoreContextController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('context.store.update');
});

Route::middleware(['auth', 'verified', 'organization.access', 'store.context'])->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard/Index'))->name('dashboard');
    Route::get('/system/status', SystemStatusController::class)
        ->middleware('permission:system.health.view')
        ->name('system.status');
    Route::get('/settings', [SystemSettingsController::class, 'index'])
        ->middleware('permission:system.settings.view')
        ->name('system.settings.index');
    Route::get('/settings/mail', [SystemSettingsController::class, 'mail'])
        ->middleware('permission:system.settings.view')
        ->name('system.settings.mail');
    Route::get('/settings/feishu', [SystemSettingsController::class, 'feishu'])
        ->middleware('permission:system.settings.view')
        ->name('system.settings.feishu');
    Route::put('/settings/general', [SystemSettingsController::class, 'updateGeneral'])
        ->middleware('permission:system.settings.update')
        ->name('system.settings.general.update');
    Route::put('/settings/mail', [SystemSettingsController::class, 'updateMail'])
        ->middleware('permission:system.settings.update')
        ->name('system.settings.mail.update');
    Route::put('/settings/feishu', [SystemSettingsController::class, 'updateFeishu'])
        ->middleware('permission:system.settings.update')
        ->name('system.settings.feishu.update');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])
        ->whereNumber('auditLog')
        ->middleware('permission:audit.view')
        ->name('audit-logs.show');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->middleware('permission:users.create')->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.update')->name('users.edit');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update')->name('users.update');
    Route::post('/users/{user}/avatar', [UserController::class, 'updateAvatar'])->middleware('permission:users.update')->name('users.avatar.update');
    Route::delete('/users/{user}/avatar', [UserController::class, 'destroyAvatar'])->middleware('permission:users.update')->name('users.avatar.destroy');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('users.destroy');
    Route::put('/users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_role')->name('users.roles.update');
    Route::put('/users/{user}/stores', [UserController::class, 'assignStores'])->middleware('permission:users.update')->name('users.stores.update');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create')->name('roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update')->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete')->name('roles.destroy');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.update')->name('roles.permissions.update');

    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view')->name('permissions.index');

    Route::get('/apps', [AppController::class, 'index'])->middleware('permission:apps.view')->name('apps.index');
    Route::get('/apps/{app}', [AppController::class, 'show'])->middleware('permission:apps.view')->name('apps.show');

    Route::get('/webhooks', [WebhookEventController::class, 'index'])->middleware('permission:webhooks.view')->name('webhooks.index');
    Route::get('/webhooks/{webhookEvent}', [WebhookEventController::class, 'show'])->middleware('permission:webhooks.view')->name('webhooks.show');
    Route::post('/webhooks/{webhookEvent}/retry', [WebhookEventController::class, 'retry'])->middleware('permission:webhooks.retry')->name('webhooks.retry');

    Route::get('/sync', [SyncJobController::class, 'index'])->middleware('permission:sync.view')->name('sync.index');
    Route::post('/sync', [SyncJobController::class, 'store'])->middleware('permission:sync.run')->name('sync.store');
    Route::get('/sync/{syncJob}', [SyncJobController::class, 'show'])->middleware('permission:sync.view')->name('sync.show');

    Route::get('/products', [ShopifyDataController::class, 'products'])->middleware('permission:products.view')->name('products.index');
    Route::get('/products/{product}', [ShopifyDataController::class, 'product'])->whereNumber('product')->middleware('permission:products.view')->name('products.show');
    Route::get('/orders', [ShopifyDataController::class, 'orders'])->middleware('permission:orders.view')->name('orders.index');
    Route::get('/orders/{order}', [ShopifyDataController::class, 'order'])->whereNumber('order')->middleware('permission:orders.view')->name('orders.show');
    Route::get('/customers', [ShopifyDataController::class, 'customers'])->middleware('permission:customers.view')->name('customers.index');
    Route::get('/customers/{customer}', [ShopifyDataController::class, 'customer'])->whereNumber('customer')->middleware('permission:customers.view')->name('customers.show');
    Route::get('/inventory', [ShopifyDataController::class, 'inventory'])->middleware('permission:inventory.view')->name('inventory.index');
    Route::get('/inventory/{inventoryItem}', [ShopifyDataController::class, 'inventoryItem'])->whereNumber('inventoryItem')->middleware('permission:inventory.view')->name('inventory.show');

    Route::get('/stores', [StoreController::class, 'index'])->middleware('permission:store.view')->name('stores.index');
    Route::get('/stores/create', [StoreController::class, 'create'])->middleware('permission:store.create')->name('stores.create');
    Route::post('/stores', [StoreController::class, 'store'])->middleware('permission:store.create')->name('stores.store');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->middleware(['store.access', 'permission:store.view'])->name('stores.show');
    Route::post('/stores/{store}/connect', [StoreController::class, 'connect'])->middleware(['store.access', 'permission:store.connect'])->name('stores.connect');
    Route::post('/stores/{store}/shopify/verify', ShopifyConnectionHealthController::class)->middleware(['store.access', 'permission:store.connect'])->name('stores.shopify.verify');
    Route::post('/stores/{store}/shopify/disconnect', ShopifyConnectionDisconnectController::class)->middleware(['store.access', 'permission:store.disconnect'])->name('stores.shopify.disconnect');

    Route::get('/stores/{store}/access-check', fn (Store $store) => response()->json(['data' => ['id' => $store->id]]))
        ->middleware(['store.access', 'permission:store.view'])
        ->name('stores.access-check');
});
