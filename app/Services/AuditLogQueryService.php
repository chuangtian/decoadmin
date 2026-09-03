<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AuditLogQueryService
{
    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'shopify_connection_connected' => 'Shopify 连接成功',
        'shopify_connection_reconnected' => 'Shopify 重新连接',
        'shopify_connection_disconnected' => 'Shopify 连接已断开',
        'shopify_connection_invalid' => 'Shopify 连接失效',
        'shopify_connection_warning' => 'Shopify 连接警告',
        'shopify_webhook_retried' => 'Webhook 重新处理',
        'shopify_sync_retried' => '同步任务重新执行',
        'system_settings_updated' => '系统设置已更新',
        'brand_social_post_visibility_updated' => '品牌官媒帖子显示状态已更新',
        'brand_social_daily_review_created' => '品牌官媒每日复盘已创建',
        'brand_social_daily_review_updated' => '品牌官媒每日复盘已更新',
        'brand_social_daily_review_deleted' => '品牌官媒每日复盘已删除',
        'brand_social_weekly_report_created' => '品牌官媒周报已创建',
        'brand_social_weekly_report_updated' => '品牌官媒周报已更新',
        'brand_social_weekly_report_deleted' => '品牌官媒周报已删除',
    ];

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'access_token', 'refresh_token', 'token', 'secret', 'password', 'authorization',
        'cookie', 'client_secret', 'api_key', 'hmac', 'signature', 'private_key',
    ];

    /**
     * @param  array{search?: string, action?: string, user_id?: int, store_id?: int, date_from?: string, date_to?: string}  $filters
     */
    public function paginate(User $user, Organization $organization, array $filters, string $timezone = 'UTC'): LengthAwarePaginator
    {
        return $this->applyFilters($this->scopedQuery($user, $organization), $filters, $timezone)
            ->with(['user:id,name,email', 'store:id,organization_id,name,shopify_domain,timezone'])
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $audit): array => $this->summaryItem($audit));
    }

    public function find(User $user, Organization $organization, int $id): AuditLog
    {
        return $this->scopedQuery($user, $organization)
            ->with(['user:id,name,email', 'store:id,organization_id,name,shopify_domain,timezone'])
            ->findOrFail($id);
    }

    /** @return list<array<string, mixed>> */
    public function recentForStore(User $user, Organization $organization, Store $store, int $limit = 20): array
    {
        abort_unless($store->organization_id === $organization->getKey() && $user->canAccessStore($store), 403);

        return $this->scopedQuery($user, $organization)
            ->where('store_id', $store->getKey())
            ->with(['user:id,name,email', 'store:id,organization_id,name,shopify_domain,timezone'])
            ->latest('created_at')
            ->limit(min(50, max(1, $limit)))
            ->get()
            ->map(fn (AuditLog $audit): array => $this->summaryItem($audit))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function detailItem(AuditLog $audit): array
    {
        return [
            ...$this->summaryItem($audit),
            'ip_address' => $this->maskIpAddress($audit->ip_address),
            'user_agent' => $this->safeString($audit->user_agent),
            'old_values' => $this->sanitize($audit->old_values),
            'new_values' => $this->sanitize($audit->new_values),
            'metadata' => $this->sanitize($audit->metadata),
        ];
    }

    /** @return array{total: int, last_24_hours: int, system_events: int, active_actors: int} */
    public function summary(User $user, Organization $organization): array
    {
        $query = $this->scopedQuery($user, $organization);

        return [
            'total' => (clone $query)->count(),
            'last_24_hours' => (clone $query)->where('created_at', '>=', now()->subDay())->count(),
            'system_events' => (clone $query)->whereNull('user_id')->count(),
            'active_actors' => (clone $query)->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    /** @return array{actions: list<array{value: string, label: string}>, users: list<array{id: int, name: string, email: string}>, stores: list<array{id: int, name: string}>} */
    public function filterOptions(User $user, Organization $organization): array
    {
        $storeIds = $this->accessibleStoreIds($user, $organization);
        $actions = $this->scopedQuery($user, $organization)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn (string $action): array => ['value' => $action, 'label' => $this->actionLabel($action)])
            ->values()
            ->all();

        return [
            'actions' => $actions,
            'users' => $organization->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $actor): array => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ])->values()->all(),
            'stores' => $organization->stores()
                ->whereIn('id', $storeIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($store): array => ['id' => $store->id, 'name' => $store->name])
                ->values()->all(),
        ];
    }

    private function scopedQuery(User $user, Organization $organization): Builder
    {
        $storeIds = $this->accessibleStoreIds($user, $organization);

        return AuditLog::query()
            ->where('organization_id', $organization->getKey())
            ->where(function (Builder $query) use ($storeIds): void {
                $query->whereNull('store_id')->orWhereIn('store_id', $storeIds);
            });
    }

    /**
     * @param  array{search?: string, action?: string, user_id?: int, store_id?: int, date_from?: string, date_to?: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $timezone): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $dateFrom = filled($filters['date_from'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_from'], $timezone)->utc()
            : null;
        $dateTo = filled($filters['date_to'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_to'], $timezone)->endOfDay()->utc()
            : null;

        return $query
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('uuid', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('store', fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('shopify_domain', 'like', "%{$search}%"));
                });
            })
            ->when(filled($filters['action'] ?? null), fn (Builder $query) => $query->where('action', $filters['action']))
            ->when(filled($filters['user_id'] ?? null), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(filled($filters['store_id'] ?? null), fn (Builder $query) => $query->where('store_id', $filters['store_id']))
            ->when($dateFrom, fn (Builder $query) => $query->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->where('created_at', '<=', $dateTo));
    }

    /** @return list<int> */
    private function accessibleStoreIds(User $user, Organization $organization): array
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->pluck('id')->all()
            : $user->stores()
                ->where('stores.organization_id', $organization->getKey())
                ->pluck('stores.id')
                ->all();
    }

    /** @return array<string, mixed> */
    private function summaryItem(AuditLog $audit): array
    {
        return [
            'id' => $audit->id,
            'uuid' => $audit->uuid,
            'action' => $audit->action,
            'action_label' => $this->actionLabel($audit->action),
            'category' => $this->category($audit->action),
            'result' => $this->result($audit->action),
            'actor' => $audit->user ? [
                'id' => $audit->user->id,
                'name' => $audit->user->name,
                'email' => $audit->user->email,
            ] : null,
            'store' => $audit->store ? [
                'id' => $audit->store->id,
                'name' => $audit->store->name,
                'shopify_domain' => $audit->store->shopify_domain,
                'timezone' => $audit->store->timezone ?: 'UTC',
            ] : null,
            'subject' => $audit->subject_type ? [
                'type' => class_basename($audit->subject_type),
                'id' => $audit->subject_id,
            ] : null,
            'created_at' => $audit->created_at?->toIso8601String(),
        ];
    }

    private function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? str($action)->replace('_', ' ')->headline()->toString();
    }

    private function category(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'shopify_connection_') => 'Shopify 连接',
            str_starts_with($action, 'shopify_webhook_') => 'Webhook',
            str_contains($action, 'sync') => '数据同步',
            str_contains($action, 'user') => '用户管理',
            str_contains($action, 'role'), str_contains($action, 'permission') => '权限管理',
            str_starts_with($action, 'system_settings_') => '系统设置',
            str_starts_with($action, 'brand_social_') => '品牌官媒',
            default => '系统操作',
        };
    }

    private function result(string $action): string
    {
        return match (true) {
            str_contains($action, 'invalid'), str_contains($action, 'failed') => 'error',
            str_contains($action, 'warning'), str_contains($action, 'disconnected') => 'warning',
            str_contains($action, 'connected'), str_contains($action, 'reconnected'), str_contains($action, 'retried') => 'success',
            default => 'info',
        };
    }

    private function maskIpAddress(?string $ipAddress): ?string
    {
        if (! $ipAddress) {
            return null;
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.***', $ipAddress);
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ipAddress), 0, 4)).'::';
        }

        return null;
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (collect(self::SENSITIVE_KEYS)->contains(fn (string $needle): bool => str_contains($normalizedKey, $needle))) {
                $sanitized[$key] = '[已隐藏]';

                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitize($value)
                : (is_string($value) ? $this->safeString($value) : $value);
        }

        return $sanitized;
    }

    private function safeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\bshp(?:at|ca|pa|ss)_[A-Za-z0-9_-]+\b/i', '[已隐藏]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [已隐藏]', $value) ?? $value;

        return mb_substr($value, 0, 1000);
    }
}
