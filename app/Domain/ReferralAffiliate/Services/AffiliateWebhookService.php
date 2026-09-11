<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Exceptions\AffiliateException;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyWebhookHmacValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateWebhookService
{
    public const TOPICS = ['orders/paid', 'orders/cancelled', 'refunds/create', 'app/uninstalled', 'app/scopes_update'];

    public function receive(string $raw, array $headers): WebhookEvent
    {
        if (strlen($raw) > 10 * 1024 * 1024) {
            throw new AffiliateException('PAYLOAD_TOO_LARGE', '通知载荷过大。', 413);
        }
        if (! app(ShopifyWebhookHmacValidator::class)->validate($raw, $headers['hmac'] ?? null, (string) config('referral.active.client_secret'))) {
            throw new AffiliateException('INVALID_WEBHOOK_HMAC', '通知签名无效。', 401);
        }
        $id = (string) ($headers['webhook_id'] ?? '');
        $topic = (string) ($headers['topic'] ?? '');
        if (! Str::isUuid($id) || ! in_array($topic, self::TOPICS, true)) {
            throw new AffiliateException('INVALID_WEBHOOK', '通知标识或主题无效。', 422);
        }
        $store = Store::query()->where('shopify_domain', $headers['shop_domain'] ?? '')->firstOrFail();
        app(AffiliateShopGuard::class)->store($store);
        $app = App::query()->whereNull('organization_id')->where('handle', config('referral.active.handle'))
            ->where('client_id', config('referral.active.client_id'))->where('settings->environment', config('referral.environment'))->firstOrFail();
        $payload = json_decode($raw, true);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new AffiliateException('INVALID_PAYLOAD', '通知 JSON 无效。', 422);
        }
        $hash = hash('sha256', $raw);

        return DB::transaction(function () use ($store, $app, $id, $topic, $raw, $hash) {
            $event = WebhookEvent::query()->firstOrCreate(['webhook_id' => $id], [
                'organization_id' => $store->organization_id, 'store_id' => $store->id, 'app_id' => $app->id,
                'shopify_connection_id' => $store->shopifyConnection?->id, 'topic' => $topic, 'api_version' => '2026-07',
                'headers' => ['shop_domain' => $store->shopify_domain, 'topic' => $topic, 'webhook_id' => $id],
                'payload' => ['storage' => 'encrypted'], 'payload_encrypted' => $raw, 'payload_sha256' => $hash,
                'status' => 'queued', 'received_at' => now(), 'attempts' => 0,
            ]);
            if ((int) $event->store_id !== (int) $store->id || (int) $event->app_id !== (int) $app->id
                || $event->topic !== $topic || ! hash_equals((string) $event->payload_sha256, $hash)) {
                throw new AffiliateException('WEBHOOK_ID_CONFLICT', '通知标识冲突。', 409);
            }
            if ($topic === 'app/uninstalled' && $event->wasRecentlyCreated) {
                $this->uninstall($store, $app);
            }
            if ($event->wasRecentlyCreated || $event->status === 'failed') {
                ProcessWebhookEventJob::dispatch($event->id)->onQueue('affiliate')->afterCommit();
            }

            return $event;
        });
    }

    public function process(WebhookEvent $event): void
    {
        $store = Store::query()->where('organization_id', $event->organization_id)->findOrFail($event->store_id);
        app(AffiliateShopGuard::class)->store($store);
        abort_unless($event->app?->handle === config('referral.active.handle')
            && $event->app?->client_id === config('referral.active.client_id') && $event->payloadIntegrityIsValid(), 403);
        $payload = $event->decodedPayload();
        if ($event->topic === 'app/uninstalled') {
            return;
        }
        if ($event->topic === 'app/scopes_update') {
            $scopes = $payload['current'] ?? null;
            if (! is_array($scopes) || collect($scopes)->contains(fn ($scope) => ! is_string($scope))) {
                throw new AffiliateException('INVALID_SCOPES', '权限通知格式无效。', 422);
            }
            AppInstallation::query()->where('app_id', $event->app_id)->where('store_id', $store->id)->where('status', 'active')
                ->get()->each(fn ($installation) => $installation->update(['granted_scopes' => $scopes]));

            return;
        }
        $id = $event->topic === 'refunds/create' ? ($payload['order_id'] ?? null) : ($payload['id'] ?? null);
        if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)) {
            throw new AffiliateException('INVALID_ORDER_ID', '订单标识无效。', 422);
        }
        // Re-read Shopify facts so duplicate or out-of-order deliveries converge.
        $order = app(AffiliateOrderReader::class)->read($store, (string) $id);
        app(AffiliateAccountingService::class)->reconcile($store, $order);
    }

    private function uninstall(Store $store, App $app): void
    {
        AppInstallation::query()->where('store_id', $store->id)->where('app_id', $app->id)->update([
            'status' => 'uninstalled', 'uninstalled_at' => now(), 'access_token_encrypted' => null,
            'refresh_token_encrypted' => null, 'token_type' => null, 'access_token_expires_at' => null, 'refresh_token_expires_at' => null,
        ]);
        $settings = AffiliateStoreSetting::query()->where('store_id', $store->id)->where('organization_id', $store->organization_id)->lockForUpdate()->first();
        if ($settings) {
            $old = $settings->only(['affiliate_enabled', 'customer_referral_enabled']);
            $settings->update(['affiliate_enabled' => false, 'customer_referral_enabled' => false]);
            AuditLog::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id,
                'action' => 'affiliate_settings_updated', 'subject_type' => $settings::class, 'subject_id' => $settings->id,
                'old_values' => $old, 'new_values' => $settings->only(array_keys($old)), 'metadata' => ['source' => 'app_uninstalled']]);
        }
    }
}
