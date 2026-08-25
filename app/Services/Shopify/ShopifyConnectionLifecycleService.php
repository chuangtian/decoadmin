<?php

namespace App\Services\Shopify;

use App\Models\AuditLog;
use App\Models\ShopifyConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShopifyConnectionLifecycleService
{
    public const NEW_CONNECTION = '__new_connection__';

    /** @param array{id?: string, name?: string, myshopify_domain?: string, iana_timezone?: string, currency_code?: string}|null $shop */
    public function markConnected(
        ShopifyConnection $connection,
        ?User $actor = null,
        ?string $reason = null,
        bool $apiChecked = false,
        ?array $shop = null,
        ?string $previousStatus = null,
    ): ShopifyConnection {
        $previousStatus ??= $connection->exists ? $connection->status : null;
        $auditPreviousStatus = $previousStatus === self::NEW_CONNECTION ? null : $previousStatus;
        $metadata = $connection->metadata ?? [];

        if ($shop !== null) {
            $metadata['health_shop'] = $shop;
        }

        $attributes = [
            'shopify_shop_id' => $this->numericShopId($shop['id'] ?? null) ?? $connection->shopify_shop_id,
            'status' => 'connected',
            'last_verified_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'metadata' => $metadata,
        ];

        if ($apiChecked) {
            $attributes['last_api_check'] = now();
        }

        $connection->forceFill($attributes)->save();

        if ($shop !== null) {
            $timezone = $shop['iana_timezone'] ?? null;
            $currency = $shop['currency_code'] ?? null;
            $storeAttributes = [];

            if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
                $storeAttributes['timezone'] = $timezone;
            }

            if (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $storeAttributes['currency'] = $currency;
            }

            if ($storeAttributes !== []) {
                $connection->store()->update($storeAttributes);
            }
        }

        if ($auditPreviousStatus !== 'connected') {
            $this->audit(
                $connection,
                in_array($auditPreviousStatus, ['invalid', 'disconnected'], true)
                    ? 'shopify_connection_reconnected'
                    : 'shopify_connection_connected',
                $auditPreviousStatus,
                'connected',
                $actor,
                $reason,
            );
        }

        return $connection;
    }

    public function markWarning(
        ShopifyConnection $connection,
        string $reason,
        ?User $actor = null,
        bool $apiChecked = false,
    ): ShopifyConnection {
        return $this->markFailed($connection, 'warning', $reason, $actor, $apiChecked);
    }

    public function markInvalid(
        ShopifyConnection $connection,
        string $reason,
        ?User $actor = null,
        bool $apiChecked = false,
    ): ShopifyConnection {
        return $this->markFailed($connection, 'invalid', $reason, $actor, $apiChecked);
    }

    public function markDisconnected(
        ShopifyConnection $connection,
        string $reason,
        ?User $actor = null,
        bool $apiChecked = false,
    ): ShopifyConnection {
        return $this->markFailed($connection, 'disconnected', $reason, $actor, $apiChecked);
    }

    public function markUninstalled(
        ShopifyConnection $connection,
        string $reason,
        ?User $actor = null,
    ): ShopifyConnection {
        if ($connection->uninstalled_at !== null && ! filled($connection->access_token_encrypted)) {
            $connection->appInstallations()->where('status', '!=', 'uninstalled')->update([
                'status' => 'uninstalled',
                'uninstalled_at' => $connection->uninstalled_at,
            ]);

            return $connection->refresh();
        }

        $previousStatus = $connection->status;
        $safeReason = $this->safeReason($connection, $reason);
        $metadata = $connection->metadata ?? [];
        $metadata['personal_data_erase_at'] = now()->addHours(48)->toIso8601String();
        $metadata['configuration_purge_at'] = now()->addDays(30)->toIso8601String();
        unset($metadata['personal_data_erased_at'], $metadata['configuration_purged_at']);

        DB::transaction(function () use ($connection, $previousStatus, $safeReason, $metadata, $actor): void {
            $connection->forceFill([
                'access_token_encrypted' => null,
                'refresh_token_encrypted' => null,
                'access_token_expires_at' => null,
                'status' => 'disconnected',
                'uninstalled_at' => now(),
                'last_error' => $safeReason,
                'last_error_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $connection->appInstallations()->update([
                'status' => 'uninstalled',
                'uninstalled_at' => now(),
            ]);

            $connection->store?->syncJobs()
                ->whereIn('status', ['pending', 'queued', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'last_error' => 'Shopify 应用已卸载，任务自动取消。',
                    'error_code' => 'shopify_app_uninstalled',
                ]);

            $this->audit(
                $connection,
                'shopify_app_uninstalled',
                $previousStatus,
                'disconnected',
                $actor,
                $safeReason,
            );
        });

        return $connection->refresh();
    }

    private function markFailed(
        ShopifyConnection $connection,
        string $status,
        string $reason,
        ?User $actor,
        bool $apiChecked,
    ): ShopifyConnection {
        $previousStatus = $connection->status;
        $safeReason = $this->safeReason($connection, $reason);
        $attributes = [
            'status' => $status,
            'last_error' => $safeReason,
            'last_error_at' => now(),
        ];

        if ($apiChecked) {
            $attributes['last_api_check'] = now();
        }

        $connection->forceFill($attributes)->save();

        if ($previousStatus !== $status && in_array($status, ['invalid', 'disconnected'], true)) {
            $this->audit(
                $connection,
                "shopify_connection_{$status}",
                $previousStatus,
                $status,
                $actor,
                $safeReason,
            );
        }

        return $connection;
    }

    private function audit(
        ShopifyConnection $connection,
        string $action,
        ?string $previousStatus,
        string $status,
        ?User $actor,
        ?string $reason,
    ): void {
        $connection->loadMissing('store');

        AuditLog::query()->create([
            'organization_id' => $connection->store?->organization_id,
            'store_id' => $connection->store_id,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $connection->getMorphClass(),
            'subject_id' => $connection->getKey(),
            'old_values' => ['status' => $previousStatus],
            'new_values' => ['status' => $status],
            'metadata' => array_filter([
                'reason' => $reason ? $this->safeReason($connection, $reason) : null,
                'previous_status' => $previousStatus,
                'new_status' => $status,
                'api_version' => $connection->api_version,
            ], fn ($value) => $value !== null),
        ]);
    }

    private function safeReason(ShopifyConnection $connection, string $reason): string
    {
        $secrets = array_filter([
            $connection->access_token_encrypted,
            $connection->refresh_token_encrypted,
        ], fn ($secret) => is_string($secret) && $secret !== '');

        return mb_substr(str_replace($secrets, '[redacted]', $reason), 0, 1000);
    }

    private function numericShopId(?string $shopId): ?int
    {
        if (! $shopId || ! preg_match('/\/(\d+)$/', $shopId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
