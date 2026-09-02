<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\ApplicationCenterController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BusinessInsightsController;
use App\Http\Controllers\CampaignPlanningAssetController;
use App\Http\Controllers\CampaignThemeController;
use App\Http\Controllers\CodexApiTokenController;
use App\Http\Controllers\CodexOAuthAuthorizationController;
use App\Http\Controllers\CodexOAuthClientRegistrationController;
use App\Http\Controllers\CodexOAuthMetadataController;
use App\Http\Controllers\CodexOAuthTokenController;
use App\Http\Controllers\CodexRemoteMcpController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoogleAdsOAuthController;
use App\Http\Controllers\GoogleSearchConsoleOAuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\InstagramFeedController;
use App\Http\Controllers\InstagramFeedMetaCallbackController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LiveViewController;
use App\Http\Controllers\MicrosoftAdsOAuthController;
use App\Http\Controllers\ModelAssetController;
use App\Http\Controllers\NaturalTrafficController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\OrganizationContextController;
use App\Http\Controllers\PaidAdvertisingChannelController;
use App\Http\Controllers\PaidAdvertisingFacebookController;
use App\Http\Controllers\PaidAdvertisingGoalController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PersonalizationCheckoutExtensionController;
use App\Http\Controllers\PersonalizationController;
use App\Http\Controllers\PersonalizationEventController;
use App\Http\Controllers\PersonalizationStrategyWorkflowController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPersonalizationController;
use App\Http\Controllers\PublicStudentDiscountController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReputationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShopifyAppUninstallController;
use App\Http\Controllers\ShopifyConnectionHealthController;
use App\Http\Controllers\ShopifyDataController;
use App\Http\Controllers\ShopifyInstagramFeedAppController;
use App\Http\Controllers\ShopifyInstagramFeedWebhookController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\ShopifyPersonalizationAppController;
use App\Http\Controllers\ShopifyPersonalizationWebhookController;
use App\Http\Controllers\ShopifyStudentDiscountAppController;
use App\Http\Controllers\ShopifyStudentDiscountWebhookController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StoreAlertController;
use App\Http\Controllers\StoreBusinessCredentialController;
use App\Http\Controllers\StoreContextController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StorefrontEventController;
use App\Http\Controllers\StoreNotificationSettingsController;
use App\Http\Controllers\StoreStatusController;
use App\Http\Controllers\StudentDiscountController;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookEventController;
use App\Http\Controllers\YouTubeAnalyticsOAuthController;
use App\Models\Store;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/.well-known/oauth-protected-resource', [CodexOAuthMetadataController::class, 'protectedResource'])
    ->middleware('throttle:120,1')
    ->name('codex.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/mcp/decoadmin', [CodexOAuthMetadataController::class, 'protectedResource'])
    ->middleware('throttle:120,1');
Route::get('/.well-known/oauth-authorization-server', [CodexOAuthMetadataController::class, 'authorizationServer'])
    ->middleware('throttle:120,1')
    ->name('codex.oauth.authorization-server');
Route::post('/oauth/register', CodexOAuthClientRegistrationController::class)
    ->middleware('throttle:10,1')
    ->name('codex.oauth.register');
Route::post('/oauth/token', [CodexOAuthTokenController::class, 'token'])
    ->middleware('throttle:60,1')
    ->name('codex.oauth.token');
Route::post('/oauth/revoke', [CodexOAuthTokenController::class, 'revoke'])
    ->middleware('throttle:60,1')
    ->name('codex.oauth.revoke');
Route::post('/mcp/decoadmin', CodexRemoteMcpController::class)
    ->middleware(['codex.token', 'throttle:120,1'])
    ->name('codex.mcp');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::get('/shopify/oauth/callback', [ShopifyOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('shopify.oauth.callback');

Route::post('/shopify/webhooks/{app:handle}', ShopifyWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('shopify.webhooks.receive');

Route::get('/shopify-app/student-discounts', [ShopifyStudentDiscountAppController::class, 'management'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->name('student-discounts.shopify-app.management');

Route::post('/api/shopify-app/webhooks', ShopifyStudentDiscountWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('student-discounts.shopify-app.webhooks');

Route::post('/shopify/pixels/{store:analytics_ingest_key}', StorefrontEventController::class)
    ->middleware('throttle:300,1')
    ->name('shopify.pixels.receive');
Route::options('/shopify/pixels/{store:analytics_ingest_key}', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type',
]))->name('shopify.pixels.options');

Route::prefix('/api/shopify-app/student-discounts')->group(function (): void {
    Route::get('/connection', [ShopifyStudentDiscountAppController::class, 'connection'])
        ->middleware(['shopify.id-token', 'throttle:60,1'])
        ->name('student-discounts.shopify-app.connection');
    Route::post('/bootstrap', [ShopifyStudentDiscountAppController::class, 'bootstrap'])
        ->middleware(['shopify.id-token', 'throttle:20,1'])
        ->name('student-discounts.shopify-app.bootstrap');
});

Route::prefix('/api/shopify-app/student-discounts/proxy')
    ->middleware(['shopify.app-proxy', 'shopify.app-proxy-response', 'throttle:student-discount-public'])
    ->group(function (): void {
        Route::get('/', [PublicStudentDiscountController::class, 'info'])
            ->name('student-discounts.public.info');
        Route::get('/verify', [PublicStudentDiscountController::class, 'retryPage'])
            ->name('student-discounts.public.retry');
        Route::post('/verify/claims', [PublicStudentDiscountController::class, 'retryStore'])
            ->middleware('throttle:student-discount-submissions')
            ->name('student-discounts.public.retry.store');
        Route::post('/', [PublicStudentDiscountController::class, 'store'])
            ->middleware('throttle:student-discount-submissions');
        Route::post('/claims', [PublicStudentDiscountController::class, 'store'])
            ->middleware('throttle:student-discount-submissions')
            ->name('student-discounts.public.claims.store');
        Route::get('/claims/{claim}', [PublicStudentDiscountController::class, 'show'])
            ->name('student-discounts.public.claims.show');
    });

// instagram-feed Shopify App。前台数据走 app-data metafield，不需要 App Proxy。
Route::get('/shopify-app/instagram-feed', [ShopifyInstagramFeedAppController::class, 'management'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->name('instagram-feed.shopify-app.management');

Route::post('/api/shopify-app/instagram-feed/webhooks', ShopifyInstagramFeedWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('instagram-feed.shopify-app.webhooks');

Route::prefix('/api/shopify-app/instagram-feed')->group(function (): void {
    Route::get('/connection', [ShopifyInstagramFeedAppController::class, 'connection'])
        ->middleware(['shopify.id-token:instagram_feed', 'throttle:60,1'])
        ->name('instagram-feed.shopify-app.connection');
    Route::post('/bootstrap', [ShopifyInstagramFeedAppController::class, 'bootstrap'])
        ->middleware(['shopify.id-token:instagram_feed', 'throttle:20,1'])
        ->name('instagram-feed.shopify-app.bootstrap');
});

Route::get('/shopify-app/personalization', [ShopifyPersonalizationAppController::class, 'management'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->name('personalization.shopify-app.management');

Route::post('/api/shopify-app/personalization/webhooks', ShopifyPersonalizationWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('personalization.shopify-app.webhooks');

Route::post('/api/shopify-app/personalization/events/{source}', PersonalizationEventController::class)
    ->middleware('throttle:600,1')
    ->name('personalization.events.receive');
Route::options('/api/shopify-app/personalization/events/{source}', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type',
    'Cache-Control' => 'no-store',
]))->name('personalization.events.options');

Route::prefix('/api/shopify-app/personalization')->group(function (): void {
    Route::get('/connection', [ShopifyPersonalizationAppController::class, 'connection'])
        ->middleware(['shopify.id-token:personalization', 'throttle:60,1'])
        ->name('personalization.shopify-app.connection');
    Route::post('/bootstrap', [ShopifyPersonalizationAppController::class, 'bootstrap'])
        ->middleware(['shopify.id-token:personalization', 'throttle:20,1'])
        ->name('personalization.shopify-app.bootstrap');
});

Route::get('/api/shopify-app/personalization/checkout/configuration', PersonalizationCheckoutExtensionController::class)
    ->middleware(['shopify.checkout-token:personalization', 'throttle:120,1'])
    ->name('personalization.checkout.configuration');
Route::options('/api/shopify-app/personalization/checkout/configuration', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
    'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
    'Cache-Control' => 'no-store',
]))->name('personalization.checkout.configuration.options');
Route::post('/api/shopify-app/personalization/checkout/recommendations', [PersonalizationCheckoutExtensionController::class, 'recommendations'])
    ->middleware(['shopify.checkout-token:personalization', 'throttle:120,1'])
    ->name('personalization.checkout.recommendations');
Route::options('/api/shopify-app/personalization/checkout/recommendations', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
    'Cache-Control' => 'no-store',
]))->name('personalization.checkout.recommendations.options');

Route::prefix('/api/shopify-app/personalization/proxy')
    ->middleware(['shopify.app-proxy:personalization,personalization_store', 'throttle:personalization-public'])
    ->group(function (): void {
        Route::get('/recommendations/{component}', [PublicPersonalizationController::class, 'recommendations'])
            ->name('personalization.public.recommendations');
        Route::get('/smart-cart', [PublicPersonalizationController::class, 'smartCart'])
            ->name('personalization.public.smart-cart');
    });

// Meta 侧的公开回调：OAuth 靠一次性 state，合规回调靠 signed_request 验签。
Route::prefix('/instagram-feed')->group(function (): void {
    Route::get('/oauth/{provider}/callback', [InstagramFeedMetaCallbackController::class, 'oauthCallback'])
        ->whereIn('provider', ['instagram', 'facebook'])
        ->middleware('throttle:30,1')
        ->name('instagram-feed.meta.oauth.callback');

    Route::post('/meta/deauthorize', [InstagramFeedMetaCallbackController::class, 'deauthorize'])
        ->middleware('throttle:60,1')
        ->name('instagram-feed.meta.deauthorize');
    Route::get('/meta/deauthorize', [InstagramFeedMetaCallbackController::class, 'probe'])
        ->middleware('throttle:60,1')
        ->name('instagram-feed.meta.deauthorize.probe');

    Route::post('/meta/data-deletion', [InstagramFeedMetaCallbackController::class, 'dataDeletion'])
        ->middleware('throttle:60,1')
        ->name('instagram-feed.meta.data-deletion');
    Route::get('/meta/data-deletion', [InstagramFeedMetaCallbackController::class, 'dataDeletionStatus'])
        ->middleware('throttle:60,1')
        ->name('instagram-feed.meta.data-deletion.status');
});

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
    Route::get('/oauth/authorize', [CodexOAuthAuthorizationController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('codex.oauth.authorize');
    Route::post('/oauth/authorize', [CodexOAuthAuthorizationController::class, 'store'])
        ->middleware('throttle:30,1');

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
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/campaign-themes', CampaignThemeController::class)->middleware('permission:reports.view')->name('campaign-themes.index');
    Route::post('/campaign-themes/refresh', [CampaignThemeController::class, 'refresh'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('campaign-themes.refresh');
    Route::get('/business/insights', BusinessInsightsController::class)->middleware('permission:reports.view')->name('business.insights');
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview'])->middleware('permission:orders.view')->name('analytics.overview');
    Route::get('/analytics/sales', [AnalyticsController::class, 'sales'])->middleware('permission:orders.view')->name('analytics.sales');
    Route::get('/analytics/model-sales', [AnalyticsController::class, 'modelSales'])->middleware('permission:reports.view')->name('analytics.model-sales');
    Route::get('/analytics/stores', [AnalyticsController::class, 'stores'])->middleware('permission:store.view')->name('analytics.stores');
    Route::get('/analytics/live', [LiveViewController::class, 'index'])->middleware('permission:orders.view')->name('analytics.live');
    Route::get('/analytics/live/data', [LiveViewController::class, 'data'])->middleware('permission:orders.view')->name('analytics.live.data');
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('reports.index');
    Route::put('/reports/{report}/pin', [ReportController::class, 'pin'])->middleware('permission:reports.view')->name('reports.pin');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->middleware('permission:reports.view')->name('reports.show');
    Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])->middleware('permission:reports.export')->name('reports.export');
    foreach (array_keys(NaturalTrafficController::CHANNELS) as $naturalTrafficChannel) {
        Route::get("/natural-traffic/{$naturalTrafficChannel}", NaturalTrafficController::class)
            ->defaults('channel', $naturalTrafficChannel)
            ->middleware('permission:reports.view')
            ->name("natural-traffic.{$naturalTrafficChannel}");
    }
    foreach (array_diff(array_keys(NaturalTrafficController::CHANNELS), ['seo-geo']) as $naturalTrafficChannel) {
        Route::post("/natural-traffic/{$naturalTrafficChannel}/refresh", [NaturalTrafficController::class, 'refreshChannel'])
            ->defaults('channel', $naturalTrafficChannel)
            ->middleware(['permission:sync.run', 'throttle:6,1'])
            ->name("natural-traffic.{$naturalTrafficChannel}.refresh");
    }
    Route::post('/natural-traffic/brand-media/import', [NaturalTrafficController::class, 'importBrandMedia'])
        ->middleware(['permission:sync.run', 'throttle:12,1'])
        ->name('natural-traffic.brand-media.import');
    Route::post('/natural-traffic/seo-geo/refresh', [NaturalTrafficController::class, 'refresh'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('natural-traffic.seo-geo.refresh');
    Route::post('/natural-traffic/seo-geo/overview/refresh', [NaturalTrafficController::class, 'refreshOverview'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('natural-traffic.seo-geo.overview.refresh');
    Route::get('/natural-traffic/seo-geo/overview/sync-status/{syncRun}', [NaturalTrafficController::class, 'overviewSyncStatus'])
        ->whereUuid('syncRun')
        ->middleware(['permission:sync.run', 'throttle:120,1'])
        ->name('natural-traffic.seo-geo.overview.sync-status');
    Route::get('/natural-traffic/seo-geo/source-details', [NaturalTrafficController::class, 'sourceDetails'])
        ->middleware(['permission:reports.view', 'throttle:180,1'])
        ->name('natural-traffic.seo-geo.source-details');
    Route::get('/reputation/overview', [ReputationController::class, 'overview'])
        ->middleware('permission:reports.view')
        ->name('reputation.overview');
    Route::get('/reputation/risks', [ReputationController::class, 'riskSync'])
        ->middleware('permission:reports.view')
        ->name('reputation.risks');
    Route::post('/reputation/sync', [ReputationController::class, 'sync'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('reputation.sync');
    Route::get('/reputation/sync/{syncRun}', [ReputationController::class, 'syncStatus'])
        ->whereUuid('syncRun')
        ->middleware(['permission:sync.run', 'throttle:120,1'])
        ->name('reputation.sync-status');
    Route::put('/reputation/goals', [ReputationController::class, 'upsertGoals'])
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.goals.update');
    Route::post('/reputation/mentions', [ReputationController::class, 'storeMention'])
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.mentions.store');
    Route::patch('/reputation/mentions/{reputationMention}', [ReputationController::class, 'updateMention'])
        ->whereUuid('reputationMention')
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.mentions.update');
    Route::post('/reputation/risks', [ReputationController::class, 'storeRisk'])
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.risks.store');
    Route::patch('/reputation/risks/{reputationRisk}', [ReputationController::class, 'updateRisk'])
        ->whereUuid('reputationRisk')
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.risks.update');
    Route::post('/reputation/resources', [ReputationController::class, 'storeResource'])
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.resources.store');
    Route::patch('/reputation/resources/{reputationResource}', [ReputationController::class, 'updateResource'])
        ->whereUuid('reputationResource')
        ->middleware(['permission:alerts.manage', 'throttle:30,1'])
        ->name('reputation.resources.update');
    Route::get('/paid-advertising/goals', [PaidAdvertisingGoalController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('paid-advertising.goals');
    Route::post('/paid-advertising/goals/refresh', [PaidAdvertisingGoalController::class, 'refresh'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.goals.refresh');
    Route::get('/paid-advertising/goals/refresh/{syncRun}', [PaidAdvertisingGoalController::class, 'refreshStatus'])
        ->whereUuid('syncRun')
        ->middleware(['permission:sync.run', 'throttle:120,1'])
        ->name('paid-advertising.goals.refresh-status');
    Route::post('/paid-advertising/goals', [PaidAdvertisingGoalController::class, 'store'])
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.goals.store');
    Route::put('/paid-advertising/goals/overall', [PaidAdvertisingGoalController::class, 'configureOverall'])
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.goals.overall.update');
    Route::delete('/paid-advertising/goals/overall', [PaidAdvertisingGoalController::class, 'clearOverall'])
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.goals.overall.clear');
    Route::delete('/paid-advertising/goals/{goalBoard}', [PaidAdvertisingGoalController::class, 'destroy'])
        ->whereNumber('goalBoard')
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.goals.destroy');
    Route::get('/paid-advertising/facebook', [PaidAdvertisingFacebookController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('paid-advertising.facebook');
    Route::get('/paid-advertising/facebook/status', [PaidAdvertisingFacebookController::class, 'status'])
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.facebook.status');
    Route::get('/paid-advertising/facebook/data', [PaidAdvertisingFacebookController::class, 'data'])
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.facebook.data');
    Route::get('/paid-advertising/facebook/creatives', [PaidAdvertisingFacebookController::class, 'creatives'])
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.facebook.creatives');
    Route::get('/paid-advertising/facebook/copies', [PaidAdvertisingFacebookController::class, 'copies'])
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.facebook.copies');
    Route::post('/paid-advertising/facebook/sync', [PaidAdvertisingFacebookController::class, 'sync'])
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.facebook.sync');
    foreach (['google', 'tiktok', 'bing', 'criteo'] as $advertisingChannel) {
        Route::get("/paid-advertising/{$advertisingChannel}", [PaidAdvertisingChannelController::class, 'index'])
            ->defaults('channel', $advertisingChannel)
            ->middleware('permission:reports.view')
            ->name("paid-advertising.{$advertisingChannel}");
        Route::get("/paid-advertising/{$advertisingChannel}/status", [PaidAdvertisingChannelController::class, 'status'])
            ->defaults('channel', $advertisingChannel)
            ->middleware(['permission:reports.view', 'throttle:120,1'])
            ->name("paid-advertising.{$advertisingChannel}.status");
    }
    Route::get('/paid-advertising/google/data', [PaidAdvertisingChannelController::class, 'data'])
        ->defaults('channel', 'google')
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.google.data');
    Route::post('/paid-advertising/google/sync', [PaidAdvertisingChannelController::class, 'sync'])
        ->defaults('channel', 'google')
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.google.sync');
    Route::put('/paid-advertising/google/goals/feishu', [PaidAdvertisingChannelController::class, 'updateGoogleGoalFeishu'])
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.google.goals.feishu.update');
    Route::delete('/paid-advertising/google/goals/feishu', [PaidAdvertisingChannelController::class, 'clearGoogleGoalFeishu'])
        ->middleware(['permission:store.update', 'throttle:30,1'])
        ->name('paid-advertising.google.goals.feishu.clear');
    Route::get('/paid-advertising/tiktok/data', [PaidAdvertisingChannelController::class, 'data'])
        ->defaults('channel', 'tiktok')
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.tiktok.data');
    Route::post('/paid-advertising/tiktok/sync', [PaidAdvertisingChannelController::class, 'sync'])
        ->defaults('channel', 'tiktok')
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.tiktok.sync');
    Route::get('/paid-advertising/bing/data', [PaidAdvertisingChannelController::class, 'data'])
        ->defaults('channel', 'bing')
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.bing.data');
    Route::post('/paid-advertising/bing/sync', [PaidAdvertisingChannelController::class, 'sync'])
        ->defaults('channel', 'bing')
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.bing.sync');
    Route::get('/paid-advertising/criteo/data', [PaidAdvertisingChannelController::class, 'data'])
        ->defaults('channel', 'criteo')
        ->middleware(['permission:reports.view', 'throttle:120,1'])
        ->name('paid-advertising.criteo.data');
    Route::post('/paid-advertising/criteo/sync', [PaidAdvertisingChannelController::class, 'sync'])
        ->defaults('channel', 'criteo')
        ->middleware(['permission:sync.run', 'throttle:6,1'])
        ->name('paid-advertising.criteo.sync');
    Route::get(
        '/campaign-planning-documents/{campaignPlanningDocument}/assets/{assetHash}',
        CampaignPlanningAssetController::class,
    )->middleware('permission:reports.view')->name('campaign-planning-assets.show');
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
    Route::put('/settings/student-ai', [SystemSettingsController::class, 'updateStudentAi'])
        ->middleware(['permission:system.settings.update', 'throttle:20,1'])
        ->name('system.settings.student-ai.update');
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

    Route::get('/codex-tokens', [CodexApiTokenController::class, 'index'])
        ->middleware('permission:codex.tokens.view')
        ->name('codex-tokens.index');
    Route::post('/codex-tokens', [CodexApiTokenController::class, 'store'])
        ->middleware(['permission:codex.tokens.manage', 'throttle:10,1'])
        ->name('codex-tokens.store');
    Route::delete('/codex-tokens/{codexApiToken}', [CodexApiTokenController::class, 'destroy'])
        ->middleware(['permission:codex.tokens.manage', 'throttle:20,1'])
        ->name('codex-tokens.destroy');

    Route::get('/apps', [AppController::class, 'index'])->middleware('permission:apps.view')->name('apps.index');
    Route::get('/apps/{app}', [AppController::class, 'show'])->middleware('permission:apps.view')->name('apps.show');

    Route::get('/app-center', [AppController::class, 'index'])->middleware('permission:apps.view')->name('app-center.index');
    Route::get('/app-center/{app}', [AppController::class, 'show'])->middleware('permission:apps.view')->name('app-center.show');
    Route::get('/app-installations', [ApplicationCenterController::class, 'installations'])->middleware('permission:apps.view')->name('app-installations.index');
    Route::get('/app-configurations', [ApplicationCenterController::class, 'configurations'])->middleware('permission:apps.configure')->name('app-configurations.index');
    Route::get('/app-logs', [ApplicationCenterController::class, 'logs'])->middleware('permission:audit.view')->name('app-logs.index');

    Route::get('/webhooks', [WebhookEventController::class, 'index'])->middleware('permission:webhooks.view')->name('webhooks.index');
    Route::get('/webhooks/{webhookEvent}', [WebhookEventController::class, 'show'])->middleware('permission:webhooks.view')->name('webhooks.show');
    Route::post('/webhooks/{webhookEvent}/retry', [WebhookEventController::class, 'retry'])->middleware('permission:webhooks.retry')->name('webhooks.retry');

    Route::get('/alerts', [StoreAlertController::class, 'index'])->middleware('permission:alerts.view')->name('alerts.index');
    Route::post('/alerts/scan', [StoreAlertController::class, 'scan'])->middleware('permission:alerts.manage')->name('alerts.scan');
    Route::post('/alerts/{storeAlert}/acknowledge', [StoreAlertController::class, 'acknowledge'])->middleware('permission:alerts.manage')->name('alerts.acknowledge');
    Route::post('/alerts/{storeAlert}/resolve', [StoreAlertController::class, 'resolve'])->middleware('permission:alerts.manage')->name('alerts.resolve');

    Route::get('/notifications', [NotificationCenterController::class, 'index'])->middleware('permission:alerts.view')->name('notifications.index');
    Route::post('/notifications/{storeAlert}/resend', [NotificationCenterController::class, 'resend'])->middleware('permission:alerts.manage')->name('notifications.resend');

    Route::get('/finance', [FinanceController::class, 'index'])->middleware('permission:finance.view')->name('finance.index');
    Route::post('/finance/categories', [FinanceController::class, 'storeCategory'])->middleware('permission:finance.manage')->name('finance.categories.store');
    Route::post('/finance/entries', [FinanceController::class, 'storeEntry'])->middleware('permission:finance.manage')->name('finance.entries.store');
    Route::delete('/finance/entries/{financeEntry}', [FinanceController::class, 'destroyEntry'])->middleware('permission:finance.manage')->name('finance.entries.destroy');

    Route::get('/store-settings/status', StoreStatusController::class)->middleware('permission:store.view')->name('store-settings.status');
    Route::get('/store-settings/mail', [StoreNotificationSettingsController::class, 'mail'])->middleware('permission:store.view')->name('store-settings.mail');
    Route::put('/store-settings/mail', [StoreNotificationSettingsController::class, 'updateMail'])->middleware('permission:store.update')->name('store-settings.mail.update');
    Route::patch('/store-settings/mail/enabled', [StoreNotificationSettingsController::class, 'toggleMail'])->middleware('permission:store.update')->name('store-settings.mail.toggle');
    Route::get('/store-settings/feishu', [StoreNotificationSettingsController::class, 'feishu'])->middleware('permission:store.view')->name('store-settings.feishu');
    Route::put('/store-settings/feishu', [StoreNotificationSettingsController::class, 'updateFeishu'])->middleware('permission:store.update')->name('store-settings.feishu.update');
    Route::patch('/store-settings/feishu/enabled', [StoreNotificationSettingsController::class, 'toggleFeishu'])->middleware('permission:store.update')->name('store-settings.feishu.toggle');
    Route::get('/store-settings/feishu/data-links/{section}/{field}/reveal', [StoreNotificationSettingsController::class, 'revealFeishuDataLink'])->middleware(['permission:store.update', 'throttle:30,1'])->name('store-settings.feishu.data-links.reveal');
    Route::put('/store-settings/feishu/data-links/{section}', [StoreNotificationSettingsController::class, 'updateFeishuDataLinks'])->middleware('permission:store.update')->name('store-settings.feishu.data-links.update');
    Route::get('/store-settings/credentials', [StoreBusinessCredentialController::class, 'index'])->middleware('permission:store.view')->name('store-settings.credentials');
    Route::get('/store-settings/credentials/google-ads/oauth/redirect', [GoogleAdsOAuthController::class, 'redirect'])->middleware(['permission:store.update', 'throttle:30,1'])->name('google-ads.oauth.redirect');
    Route::get('/store-settings/credentials/google-ads/oauth/callback', [GoogleAdsOAuthController::class, 'callback'])->middleware(['permission:store.update', 'throttle:30,1'])->name('google-ads.oauth.callback');
    Route::get('/store-settings/credentials/bing-ads/oauth/redirect', [MicrosoftAdsOAuthController::class, 'redirect'])->middleware(['permission:store.update', 'throttle:30,1'])->name('bing-ads.oauth.redirect');
    Route::get('/store-settings/credentials/bing-ads/oauth/callback', [MicrosoftAdsOAuthController::class, 'callback'])->middleware(['permission:store.update', 'throttle:30,1'])->name('bing-ads.oauth.callback');
    Route::get('/store-settings/credentials/google-search-console/oauth/redirect', [GoogleSearchConsoleOAuthController::class, 'redirect'])->middleware(['permission:store.update', 'throttle:30,1'])->name('google-search-console.oauth.redirect');
    Route::get('/store-settings/credentials/google-search-console/oauth/callback', [GoogleSearchConsoleOAuthController::class, 'callback'])->middleware(['permission:store.update', 'throttle:30,1'])->name('google-search-console.oauth.callback');
    Route::get('/store-settings/credentials/youtube/oauth/redirect', [YouTubeAnalyticsOAuthController::class, 'redirect'])->middleware(['permission:store.update', 'throttle:30,1'])->name('youtube-analytics.oauth.redirect');
    Route::get('/store-settings/credentials/youtube/oauth/callback', [YouTubeAnalyticsOAuthController::class, 'callback'])->middleware(['permission:store.update', 'throttle:30,1'])->name('youtube-analytics.oauth.callback');
    Route::get('/store-settings/credentials/{provider}/{credentialKey}/reveal', [StoreBusinessCredentialController::class, 'reveal'])->middleware(['permission:store.update', 'throttle:30,1'])->name('store-settings.credentials.reveal');
    Route::put('/store-settings/credentials/{provider}/{credentialKey}', [StoreBusinessCredentialController::class, 'update'])->middleware('permission:store.update')->name('store-settings.credentials.update');
    Route::delete('/store-settings/credentials/{provider}/{credentialKey}', [StoreBusinessCredentialController::class, 'destroy'])->middleware('permission:store.update')->name('store-settings.credentials.destroy');

    Route::get('/sync', [SyncJobController::class, 'index'])->middleware('permission:sync.view')->name('sync.index');
    Route::post('/sync', [SyncJobController::class, 'store'])->middleware('permission:sync.run')->name('sync.store');
    Route::get('/sync/{syncJob}', [SyncJobController::class, 'show'])->middleware('permission:sync.view')->name('sync.show');
    Route::post('/sync/{syncJob}/retry', [SyncJobController::class, 'retry'])->middleware('permission:sync.retry')->name('sync.retry');

    Route::get('/products', [ShopifyDataController::class, 'products'])->middleware('permission:products.view')->name('products.index');
    Route::get('/products/{product}', [ShopifyDataController::class, 'product'])->whereNumber('product')->middleware('permission:products.view')->name('products.show');
    Route::get('/orders', [ShopifyDataController::class, 'orders'])->middleware('permission:orders.view')->name('orders.index');
    Route::get('/orders/{order}', [ShopifyDataController::class, 'order'])->whereNumber('order')->middleware('permission:orders.view')->name('orders.show');
    Route::get('/customers', [ShopifyDataController::class, 'customers'])->middleware('permission:customers.view')->name('customers.index');
    Route::get('/customers/{customer}', [ShopifyDataController::class, 'customer'])->whereNumber('customer')->middleware('permission:customers.view')->name('customers.show');
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view')->name('inventory.index');
    Route::get('/inventory/{inventoryItem}', [InventoryController::class, 'show'])->whereNumber('inventoryItem')->middleware('permission:inventory.view')->name('inventory.show');
    Route::get('/locations', [ShopifyDataController::class, 'locations'])->middleware('permission:inventory.view')->name('locations.index');
    Route::get('/locations/{location}', [ShopifyDataController::class, 'location'])->whereNumber('location')->middleware('permission:inventory.view')->name('locations.show');
    Route::get('/model-assets', [ModelAssetController::class, 'index'])->middleware('permission:products.view')->name('model-assets.index');
    Route::post('/model-assets/folders', [ModelAssetController::class, 'storeFolder'])->middleware(['permission:products.update', 'throttle:30,1'])->name('model-assets.folders.store');
    Route::get('/model-assets/folders/{folder}', [ModelAssetController::class, 'showFolder'])->whereUuid('folder')->middleware('permission:products.view')->name('model-assets.folders.show');
    Route::post('/model-assets/folders/{folder}/images', [ModelAssetController::class, 'upload'])->whereUuid('folder')->middleware(['permission:products.update', 'throttle:120,1'])->name('model-assets.images.store');
    Route::delete('/model-assets/folders/{folder}/images', [ModelAssetController::class, 'clearFolder'])->whereUuid('folder')->middleware(['permission:products.update', 'throttle:30,1'])->name('model-assets.folders.clear');
    Route::delete('/model-assets/folders/{folder}', [ModelAssetController::class, 'destroyFolder'])->whereUuid('folder')->middleware(['permission:products.update', 'throttle:30,1'])->name('model-assets.folders.destroy');
    Route::get('/model-assets/images/{image}/thumbnail', [ModelAssetController::class, 'thumbnail'])->whereUuid('image')->middleware('permission:products.view')->name('model-assets.images.thumbnail');
    Route::get('/model-assets/images/{image}', [ModelAssetController::class, 'image'])->whereUuid('image')->middleware('permission:products.view')->name('model-assets.images.content');
    Route::delete('/model-assets/images/{image}', [ModelAssetController::class, 'destroyImage'])->whereUuid('image')->middleware(['permission:products.update', 'throttle:60,1'])->name('model-assets.images.destroy');

    Route::get('/stores', [StoreController::class, 'index'])->middleware('permission:store.view')->name('stores.index');
    Route::get('/stores/create', [StoreController::class, 'create'])->middleware('permission:store.create')->name('stores.create');
    Route::post('/stores', [StoreController::class, 'store'])->middleware('permission:store.create')->name('stores.store');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->middleware(['store.access', 'permission:store.view'])->name('stores.show');
    Route::post('/stores/{store}/connect', [StoreController::class, 'connect'])->middleware(['store.access', 'permission:apps.install'])->name('stores.connect');
    Route::post('/stores/{store}/shopify/verify', ShopifyConnectionHealthController::class)->middleware(['store.access', 'permission:store.connect'])->name('stores.shopify.verify');
    Route::post('/stores/{store}/shopify/uninstall', ShopifyAppUninstallController::class)->middleware(['store.access', 'permission:apps.uninstall'])->name('stores.shopify.uninstall');
    Route::get('/stores/{store}/notifications', [StoreNotificationSettingsController::class, 'show'])->middleware(['store.access', 'permission:store.view'])->name('stores.notifications.show');
    Route::put('/stores/{store}/notifications', [StoreNotificationSettingsController::class, 'update'])->middleware(['store.access', 'permission:store.update'])->name('stores.notifications.update');
    Route::get('/stores/{store}/access-check', fn (Store $store) => response()->json(['data' => ['id' => $store->id]]))
        ->middleware(['store.access', 'permission:store.view'])
        ->name('stores.access-check');
});

Route::prefix('/organizations/{organization}/stores/{store}/student-discounts')
    ->middleware(['auth', 'verified', 'organization.access', 'store.access'])
    ->group(function (): void {
        Route::get('/', [StudentDiscountController::class, 'index'])
            ->name('student-discounts.index');
        Route::put('/campaign', [StudentDiscountController::class, 'updateCampaign'])
            ->middleware(['permission:student_discount.campaign.manage', 'throttle:30,1'])
            ->name('student-discounts.campaign.update');
        Route::put('/email-templates', [StudentDiscountController::class, 'updateEmailTemplates'])
            ->middleware(['permission:student_discount.email_template.manage', 'throttle:30,1'])
            ->name('student-discounts.email-templates.update');
        Route::post('/email-templates/test', [StudentDiscountController::class, 'testEmailTemplate'])
            ->middleware(['permission:student_discount.email_template.manage', 'throttle:10,1'])
            ->name('student-discounts.email-templates.test');
        Route::post('/claims/{claim}/approve', [StudentDiscountController::class, 'approve'])
            ->middleware(['permission:student_discount.approve', 'throttle:30,1'])
            ->name('student-discounts.claims.approve');
        Route::post('/claims/{claim}/reject', [StudentDiscountController::class, 'reject'])
            ->middleware(['permission:student_discount.reject', 'throttle:30,1'])
            ->name('student-discounts.claims.reject');
        Route::post('/claims/bulk-approve', [StudentDiscountController::class, 'bulkApprove'])
            ->middleware(['permission:student_discount.approve', 'throttle:10,1'])
            ->name('student-discounts.claims.bulk-approve');
        Route::post('/claims/bulk-reject', [StudentDiscountController::class, 'bulkReject'])
            ->middleware(['permission:student_discount.reject', 'throttle:10,1'])
            ->name('student-discounts.claims.bulk-reject');
        Route::post('/claims/usage-sync', [StudentDiscountController::class, 'syncUsage'])
            ->middleware(['permission:student_discount.claim.read', 'throttle:10,1'])
            ->name('student-discounts.claims.usage-sync');
        Route::delete('/claims/{claim}', [StudentDiscountController::class, 'destroy'])
            ->middleware(['permission:student_discount.claim.delete', 'throttle:30,1'])
            ->name('student-discounts.claims.destroy');
        Route::get('/claims/{claim}/evidence', [StudentDiscountController::class, 'evidence'])
            ->middleware(['permission:student_discount.view_evidence', 'throttle:60,1'])
            ->name('student-discounts.claims.evidence');
    });

Route::prefix('/organizations/{organization}/stores/{store}/personalization')
    ->middleware(['auth', 'verified', 'organization.access', 'store.access'])
    ->group(function (): void {
        Route::get('/', [PersonalizationController::class, 'index'])
            ->middleware('permission:personalization.view')
            ->name('personalization.index');
        Route::post('/strategies', [PersonalizationController::class, 'storeStrategy'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.strategies.store');
        Route::post('/strategy-workflow/drafts', [PersonalizationStrategyWorkflowController::class, 'createDraft'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.strategy-workflow.drafts.create');
        Route::get('/strategy-workflow/{strategy}', [PersonalizationStrategyWorkflowController::class, 'editor'])
            ->middleware(['permission:personalization.view', 'throttle:60,1'])
            ->name('personalization.strategy-workflow.editor');
        Route::patch('/strategy-workflow/{strategy}/draft', [PersonalizationStrategyWorkflowController::class, 'autosave'])
            ->middleware(['permission:personalization.manage', 'throttle:120,1'])
            ->name('personalization.strategy-workflow.autosave');
        Route::post('/strategy-workflow/{strategy}/publish', [PersonalizationStrategyWorkflowController::class, 'publish'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.strategy-workflow.publish');
        Route::post('/strategy-workflow/{strategy}/duplicate', [PersonalizationStrategyWorkflowController::class, 'duplicate'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.strategy-workflow.duplicate');
        Route::post('/strategy-workflow/{strategy}/disable', [PersonalizationStrategyWorkflowController::class, 'disable'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.strategy-workflow.disable');
        Route::delete('/strategy-workflow/{strategyUuid}', [PersonalizationStrategyWorkflowController::class, 'destroy'])
            ->whereUuid('strategyUuid')
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.strategy-workflow.destroy');
        Route::post('/strategy-workflow/{strategy}/versions/{version}/restore', [PersonalizationStrategyWorkflowController::class, 'restoreVersion'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.strategy-workflow.versions.restore');
        Route::post('/strategy-workflow/{strategy}/preview', [PersonalizationStrategyWorkflowController::class, 'preview'])
            ->middleware(['permission:personalization.view', 'throttle:60,1'])
            ->name('personalization.strategy-workflow.preview');
        Route::put('/global-settings', [PersonalizationStrategyWorkflowController::class, 'saveGlobalSettings'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.global-settings.update');
        Route::get('/discounts', [PersonalizationStrategyWorkflowController::class, 'discounts'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.discounts.index');
        Route::post('/discounts', [PersonalizationStrategyWorkflowController::class, 'createDiscount'])
            ->middleware(['permission:personalization.manage', 'throttle:10,1'])
            ->name('personalization.discounts.store');
        Route::patch('/discounts', [PersonalizationStrategyWorkflowController::class, 'updateDiscount'])
            ->middleware(['permission:personalization.manage', 'throttle:10,1'])
            ->name('personalization.discounts.update');
        Route::put('/strategies/{strategy}', [PersonalizationController::class, 'updateStrategy'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.strategies.update');
        Route::put('/strategies/{strategy}/rules', [PersonalizationController::class, 'updateRules'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.strategies.rules.update');
        Route::put('/strategies/{strategy}/products', [PersonalizationController::class, 'updateProducts'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.strategies.products.update');
        Route::post('/components', [PersonalizationController::class, 'storeComponent'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.components.store');
        Route::put('/components/{component}', [PersonalizationController::class, 'updateComponent'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.components.update');
        Route::put('/components/{component}/style', [PersonalizationController::class, 'updateStyle'])
            ->middleware(['permission:personalization.manage', 'throttle:30,1'])
            ->name('personalization.components.style.update');
        Route::post('/components/{component}/activate', [PersonalizationController::class, 'activateComponent'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.components.activate');
        Route::post('/components/{component}/disable', [PersonalizationController::class, 'disableComponent'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.components.disable');
        Route::get('/components/{component}/preview', [PersonalizationController::class, 'preview'])
            ->middleware(['permission:personalization.view', 'throttle:60,1'])
            ->name('personalization.components.preview');
        Route::put('/checkout', [PersonalizationController::class, 'saveCheckout'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.checkout.update');
        Route::put('/thank-you', [PersonalizationController::class, 'saveThankYou'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.thank-you.update');
        Route::put('/order-status', [PersonalizationController::class, 'saveOrderStatus'])
            ->middleware(['permission:personalization.manage', 'throttle:20,1'])
            ->name('personalization.order-status.update');
        Route::put('/smart-cart', [PersonalizationController::class, 'saveSmartCart'])
            ->middleware(['permission:personalization.smart_cart.manage', 'throttle:20,1'])
            ->name('personalization.smart-cart.update');
    });

Route::prefix('/organizations/{organization}/stores/{store}/instagram-feed')
    ->middleware(['auth', 'verified', 'organization.access', 'store.access'])
    ->group(function (): void {
        Route::get('/', [InstagramFeedController::class, 'index'])
            ->middleware('permission:instagram_feed.view')
            ->name('instagram-feed.index');

        Route::post('/connect', [InstagramFeedController::class, 'connect'])
            ->middleware(['permission:instagram_feed.connect', 'throttle:20,1'])
            ->name('instagram-feed.connect');
        Route::post('/select-page', [InstagramFeedController::class, 'selectPage'])
            ->middleware(['permission:instagram_feed.connect', 'throttle:20,1'])
            ->name('instagram-feed.select-page');
        Route::delete('/account', [InstagramFeedController::class, 'disconnect'])
            ->middleware(['permission:instagram_feed.connect', 'throttle:10,1'])
            ->name('instagram-feed.disconnect');

        // 同步与转存会打 Instagram 与 R2，限流比一般写操作更严。
        Route::post('/sync', [InstagramFeedController::class, 'sync'])
            ->middleware(['permission:instagram_feed.sync', 'throttle:6,1'])
            ->name('instagram-feed.sync');
        Route::post('/mirror', [InstagramFeedController::class, 'mirror'])
            ->middleware(['permission:instagram_feed.sync', 'throttle:12,1'])
            ->name('instagram-feed.mirror');
        Route::post('/media/{media}/retry-mirror', [InstagramFeedController::class, 'retryMirror'])
            ->middleware(['permission:instagram_feed.sync', 'throttle:30,1'])
            ->name('instagram-feed.media.retry-mirror');

        Route::post('/publish', [InstagramFeedController::class, 'publish'])
            ->middleware(['permission:instagram_feed.publish', 'throttle:12,1'])
            ->name('instagram-feed.publish');

        Route::post('/galleries', [InstagramFeedController::class, 'storeGallery'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:30,1'])
            ->name('instagram-feed.galleries.store');
        Route::get('/galleries/{gallery}', [InstagramFeedController::class, 'showGallery'])
            ->middleware('permission:instagram_feed.gallery.manage')
            ->name('instagram-feed.galleries.show');
        Route::put('/galleries/{gallery}', [InstagramFeedController::class, 'updateGallery'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:30,1'])
            ->name('instagram-feed.galleries.update');
        Route::delete('/galleries/{gallery}', [InstagramFeedController::class, 'destroyGallery'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:30,1'])
            ->name('instagram-feed.galleries.destroy');
        Route::post('/galleries/{gallery}/items', [InstagramFeedController::class, 'addGalleryItems'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:60,1'])
            ->name('instagram-feed.galleries.items.store');
        Route::delete('/galleries/{gallery}/items', [InstagramFeedController::class, 'removeGalleryItems'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:60,1'])
            ->name('instagram-feed.galleries.items.destroy');
        Route::put('/galleries/{gallery}/order', [InstagramFeedController::class, 'reorderGallery'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:60,1'])
            ->name('instagram-feed.galleries.order');

        Route::put('/media/{media}/products', [InstagramFeedController::class, 'updateMediaProducts'])
            ->middleware(['permission:instagram_feed.gallery.manage', 'throttle:60,1'])
            ->name('instagram-feed.media.products');
    });
