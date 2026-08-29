<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalizationGlobalSetting;
use App\Models\Store;
use App\Models\User;

class PersonalizationGlobalSettingsService
{
    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return array<string, mixed> */
    public function configuration(Store $store, User $actor): array
    {
        $this->authorize($store, $actor, 'personalization.view');
        $setting = PersonalizationGlobalSetting::query()->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)->first();

        return [
            'default_locale' => $setting?->default_locale ?? 'zh-CN',
            'copy' => $setting?->copy ?? [
                'recommendation_heading' => '你可能还喜欢',
                'add_button' => '加入购物车',
                'checkout_heading' => 'Great Value Bundles for You',
            ],
            'attribution' => $setting?->attribution ?? $this->attribution(),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(Store $store, User $actor, array $input): array
    {
        $this->authorize($store, $actor, 'personalization.manage');
        $locale = (string) ($input['default_locale'] ?? 'zh-CN');
        if (! in_array($locale, ['zh-CN', 'en'], true)) {
            throw new PersonalizationException('INVALID_DEFAULT_LOCALE', '默认语言无效。');
        }
        $copy = $input['copy'] ?? [];
        if (! is_array($copy) || array_is_list($copy) || array_diff(array_keys($copy), [
            'recommendation_heading', 'add_button', 'checkout_heading',
        ]) !== []) {
            throw new PersonalizationException('INVALID_GLOBAL_COPY', '默认文案格式无效。');
        }
        $normalizedCopy = [];
        foreach (['recommendation_heading' => 120, 'add_button' => 60, 'checkout_heading' => 120] as $key => $maximum) {
            $value = trim((string) ($copy[$key] ?? ''));
            if ($value === '' || mb_strlen($value) > $maximum) {
                throw new PersonalizationException('INVALID_GLOBAL_COPY', '默认文案不能为空或超过允许长度。');
            }
            $normalizedCopy[$key] = $value;
        }
        $setting = PersonalizationGlobalSetting::query()->updateOrCreate(
            ['organization_id' => $store->organization_id, 'store_id' => $store->id],
            [
                'default_locale' => $locale,
                'copy' => $normalizedCopy,
                'attribution' => $this->attribution(),
                'updated_by' => $actor->id,
            ],
        );
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'personalization_global_settings_updated',
            'subject_type' => PersonalizationGlobalSetting::class,
            'subject_id' => $setting->id,
            'metadata' => ['scope' => 'store', 'default_locale' => $locale],
        ]);

        return $this->configuration($store, $actor);
    }

    /** @return array<string, mixed> */
    private function attribution(): array
    {
        return [
            'model' => PersonalizationAttributionService::MODEL,
            'window_days' => PersonalizationAttributionService::WINDOW_DAYS,
            'click_only' => true,
            'refund_cancel_reversal' => true,
        ];
    }

    private function authorize(Store $store, User $actor, string $permission): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $organization = $store->organization;
        if (! $organization instanceof Organization || $store->status !== 'active' || $organization->status !== 'active'
            || ! $actor->canAccessStore($store) || ! $actor->hasPermission($permission, $organization, $store)) {
            throw new PersonalizationException('PERSONALIZATION_ACCESS_DENIED', '无权访问当前店铺的个性化推荐设置。', 403);
        }
    }
}
