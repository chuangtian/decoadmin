<?php

namespace App\Services\Marketing;

use App\Models\AppInstallation;
use Illuminate\Validation\Rule;

class MarketingModuleCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const MODULES = [
        'personalization' => [
            'name' => '个性化',
            'product_name' => 'Personalization',
            'description' => '管理商品推荐、交叉销售和个性化展示策略。',
            'capabilities' => ['智能商品推荐', '交叉销售组件', '个性化效果分析'],
            'defaults' => ['recommendation_strategy' => 'automatic', 'product_scope' => 'available'],
            'fields' => [
                ['key' => 'recommendation_strategy', 'label' => '推荐策略', 'type' => 'select', 'options' => [
                    ['value' => 'automatic', 'label' => '自动优化'],
                    ['value' => 'best_sellers', 'label' => '优先畅销商品'],
                    ['value' => 'new_arrivals', 'label' => '优先新品'],
                ]],
                ['key' => 'product_scope', 'label' => '商品范围', 'type' => 'select', 'options' => [
                    ['value' => 'available', 'label' => '仅有库存商品'],
                    ['value' => 'all', 'label' => '全部商品'],
                ]],
            ],
        ],
        'email' => [
            'name' => '邮件',
            'product_name' => 'Email',
            'description' => '配置发件身份、订阅确认规则和营销邮件基础设置。',
            'capabilities' => ['营销活动', '自动化邮件', '订阅与退订管理'],
            'defaults' => ['consent_mode' => 'single_opt_in', 'sender_name' => '', 'sender_email' => '', 'reply_to' => ''],
            'fields' => [
                ['key' => 'consent_mode', 'label' => '订阅确认', 'type' => 'select', 'options' => [
                    ['value' => 'single_opt_in', 'label' => '单次确认'],
                    ['value' => 'double_opt_in', 'label' => '双重确认'],
                ]],
                ['key' => 'sender_name', 'label' => '发件人名称', 'type' => 'text', 'placeholder' => '例如 Macfox'],
                ['key' => 'sender_email', 'label' => '发件邮箱', 'type' => 'email', 'placeholder' => 'marketing@example.com'],
                ['key' => 'reply_to', 'label' => '回复邮箱', 'type' => 'email', 'placeholder' => 'support@example.com'],
            ],
            'credential_provider' => 'email_marketing',
        ],
        'sms' => [
            'name' => '短信',
            'product_name' => 'SMS',
            'description' => '配置短信发送身份和免打扰时段；发送前始终校验明确授权。',
            'capabilities' => ['营销短信', '自动化触达', '退订与禁止发送名单'],
            'defaults' => ['sender_id' => '', 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '09:00'],
            'fields' => [
                ['key' => 'sender_id', 'label' => '发送方标识', 'type' => 'text', 'placeholder' => '品牌名或已核准号码'],
                ['key' => 'quiet_hours_start', 'label' => '免打扰开始', 'type' => 'time'],
                ['key' => 'quiet_hours_end', 'label' => '免打扰结束', 'type' => 'time'],
            ],
            'credential_provider' => 'sms_marketing',
        ],
        'popups_forms' => [
            'name' => '弹窗与表单',
            'product_name' => 'Popups & Forms',
            'description' => '设置订阅弹窗的展示延迟、重复频率和信息采集范围。',
            'capabilities' => ['订阅弹窗', '嵌入式表单', '营销授权采集'],
            'defaults' => ['display_delay_seconds' => 5, 'repeat_after_days' => 7, 'capture_sms' => false],
            'fields' => [
                ['key' => 'display_delay_seconds', 'label' => '展示延迟（秒）', 'type' => 'number', 'min' => 0, 'max' => 60],
                ['key' => 'repeat_after_days', 'label' => '再次展示间隔（天）', 'type' => 'number', 'min' => 1, 'max' => 365],
                ['key' => 'capture_sms', 'label' => '同时采集短信授权', 'type' => 'toggle'],
            ],
        ],
        'reviews' => [
            'name' => '评论',
            'product_name' => 'Reviews',
            'description' => '管理购买后评论邀请和店铺评论展示策略。',
            'capabilities' => ['评论邀请', '评论审核', '评分组件'],
            'defaults' => ['auto_request' => true, 'request_delay_days' => 7, 'minimum_rating' => 1],
            'fields' => [
                ['key' => 'auto_request', 'label' => '自动发送评论邀请', 'type' => 'toggle'],
                ['key' => 'request_delay_days', 'label' => '发货后邀请延迟（天）', 'type' => 'number', 'min' => 1, 'max' => 60],
                ['key' => 'minimum_rating', 'label' => '前台展示最低评分', 'type' => 'number', 'min' => 1, 'max' => 5],
            ],
        ],
        'page_builder' => [
            'name' => '页面构建器',
            'product_name' => 'Page Builder',
            'description' => '管理营销页面的默认语言、发布方式和搜索引擎可见性。',
            'capabilities' => ['可视化页面', '模板管理', '发布与 SEO'],
            'defaults' => ['default_locale' => 'en-US', 'publish_mode' => 'manual', 'seo_indexable' => true],
            'fields' => [
                ['key' => 'default_locale', 'label' => '默认语言', 'type' => 'text', 'placeholder' => '例如 en-US'],
                ['key' => 'publish_mode', 'label' => '发布方式', 'type' => 'select', 'options' => [
                    ['value' => 'manual', 'label' => '审核后手动发布'],
                    ['value' => 'draft', 'label' => '始终保存为草稿'],
                ]],
                ['key' => 'seo_indexable', 'label' => '允许搜索引擎收录', 'type' => 'toggle'],
            ],
        ],
    ];

    /** @return list<array<string, mixed>> */
    public function forInstallation(AppInstallation $installation): array
    {
        $settings = $installation->settings ?? [];
        $moduleStates = is_array($settings['modules'] ?? null) ? $settings['modules'] : [];
        $moduleSettings = is_array($settings['module_settings'] ?? null) ? $settings['module_settings'] : [];

        return collect(self::MODULES)->map(function (array $module, string $handle) use ($moduleStates, $moduleSettings): array {
            $values = array_replace(
                $module['defaults'],
                is_array($moduleSettings[$handle] ?? null) ? $moduleSettings[$handle] : [],
            );

            return [
                'handle' => $handle,
                'name' => $module['name'],
                'product_name' => $module['product_name'],
                'description' => $module['description'],
                'capabilities' => $module['capabilities'],
                'enabled' => (bool) ($moduleStates[$handle] ?? true),
                'configured' => $this->isConfigured($handle, $values),
                'credential_provider' => $module['credential_provider'] ?? null,
                'fields' => collect($module['fields'])->map(fn (array $field): array => [
                    ...$field,
                    'value' => $values[$field['key']] ?? null,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function findForInstallation(AppInstallation $installation, string $handle): array
    {
        $module = collect($this->forInstallation($installation))->firstWhere('handle', $handle);
        abort_unless(is_array($module), 404);

        return $module;
    }

    /** @return array<string, mixed> */
    public function validate(string $handle, array $input): array
    {
        abort_unless(isset(self::MODULES[$handle]), 404);

        $rules = match ($handle) {
            'personalization' => [
                'recommendation_strategy' => ['required', Rule::in(['automatic', 'best_sellers', 'new_arrivals'])],
                'product_scope' => ['required', Rule::in(['available', 'all'])],
            ],
            'email' => [
                'consent_mode' => ['required', Rule::in(['single_opt_in', 'double_opt_in'])],
                'sender_name' => ['nullable', 'string', 'max:120'],
                'sender_email' => ['nullable', 'email:rfc', 'max:190'],
                'reply_to' => ['nullable', 'email:rfc', 'max:190'],
            ],
            'sms' => [
                'sender_id' => ['nullable', 'string', 'max:40'],
                'quiet_hours_start' => ['required', 'date_format:H:i'],
                'quiet_hours_end' => ['required', 'date_format:H:i'],
            ],
            'popups_forms' => [
                'display_delay_seconds' => ['required', 'integer', 'between:0,60'],
                'repeat_after_days' => ['required', 'integer', 'between:1,365'],
                'capture_sms' => ['required', 'boolean'],
            ],
            'reviews' => [
                'auto_request' => ['required', 'boolean'],
                'request_delay_days' => ['required', 'integer', 'between:1,60'],
                'minimum_rating' => ['required', 'integer', 'between:1,5'],
            ],
            'page_builder' => [
                'default_locale' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z]{2,4})?$/'],
                'publish_mode' => ['required', Rule::in(['manual', 'draft'])],
                'seo_indexable' => ['required', 'boolean'],
            ],
        };

        return validator($input, $rules)->validate();
    }

    /** @param array<string, mixed> $values */
    private function isConfigured(string $handle, array $values): bool
    {
        return match ($handle) {
            'email' => filled($values['sender_name'] ?? null) && filled($values['sender_email'] ?? null),
            'sms' => filled($values['sender_id'] ?? null),
            default => true,
        };
    }
}
