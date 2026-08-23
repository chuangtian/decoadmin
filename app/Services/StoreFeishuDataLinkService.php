<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreFeishuDataLinkService
{
    private const PROVIDER = 'feishu_data_links';

    /**
     * @var array<string, array{
     *     title: string,
     *     description: string,
     *     fields: array<string, array{label: string, env_key: string, secret: bool, placeholder: string}>
     * }>
     */
    private const SECTIONS = [
        'brand' => [
            'title' => '品牌资料',
            'description' => '品牌资料电子表格（营业执照等），用于品牌资料页。',
            'fields' => [
                'brand_spreadsheet_token' => ['label' => '电子表格 Token', 'env_key' => 'FEISHU_BRAND_SPREADSHEET_TOKEN', 'secret' => true, 'placeholder' => '输入品牌资料电子表格 Token'],
                'brand_license_sheet_id' => ['label' => '营业执照 Sheet ID', 'env_key' => 'FEISHU_BRAND_LICENSE_SHEET_ID', 'secret' => false, 'placeholder' => '输入营业执照 Sheet ID'],
                'brand_wiki_url' => ['label' => '知识库链接（展示用）', 'env_key' => 'FEISHU_BRAND_WIKI_URL', 'secret' => false, 'placeholder' => '输入飞书知识库链接'],
            ],
        ],
        'amazon' => [
            'title' => '亚马逊',
            'description' => '亚马逊店铺数据多维表格，用于亚马逊数据导入。',
            'fields' => [
                'amazon_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_AMAZON_APP_TOKEN', 'secret' => true, 'placeholder' => '输入亚马逊多维表格 App Token'],
                'amazon_table_id' => ['label' => '数据表 Table ID', 'env_key' => 'FEISHU_AMAZON_TABLE_ID', 'secret' => false, 'placeholder' => '输入亚马逊数据表 Table ID'],
                'amazon_view_id' => ['label' => '视图 View ID', 'env_key' => 'FEISHU_AMAZON_VIEW_ID', 'secret' => false, 'placeholder' => '输入亚马逊视图 View ID'],
            ],
        ],
        'campaign' => [
            'title' => '活动主题',
            'description' => '活动主题数据多维表格，用于广告活动主题页。',
            'fields' => [
                'campaign_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_CAMPAIGN_APP_TOKEN', 'secret' => true, 'placeholder' => '输入活动主题多维表格 App Token'],
                'campaign_table_id' => ['label' => '数据表 Table ID', 'env_key' => 'FEISHU_CAMPAIGN_TABLE_ID', 'secret' => false, 'placeholder' => '输入活动主题数据表 Table ID'],
                'campaign_view_id' => ['label' => '视图 View ID', 'env_key' => 'FEISHU_CAMPAIGN_VIEW_ID', 'secret' => false, 'placeholder' => '输入活动主题视图 View ID'],
            ],
        ],
        'advertising_goals' => [
            'title' => '广告目标',
            'description' => '付费广告目标多维表格；系统会通过 App Token 自动发现并同步其中的全部数据表。',
            'fields' => [
                'advertising_goals_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_ADVERTISING_GOALS_APP_TOKEN', 'secret' => true, 'placeholder' => '输入广告目标多维表格 App Token'],
            ],
        ],
        'advertising_meta_weekly' => [
            'title' => 'Meta 周数据',
            'description' => 'Meta 周度投放多维表格，用于个人 Facebook 目标的趋势图和数据汇总表。',
            'fields' => [
                'advertising_meta_weekly_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_ADVERTISING_META_WEEKLY_APP_TOKEN', 'secret' => true, 'placeholder' => '输入 Meta 周数据 App Token'],
                'advertising_meta_weekly_table_id' => ['label' => '数据表 Table ID', 'env_key' => 'FEISHU_ADVERTISING_META_WEEKLY_TABLE_ID', 'secret' => false, 'placeholder' => '输入 Meta 周数据 Table ID'],
            ],
        ],
        'seo_geo' => [
            'title' => 'SEO / GEO',
            'description' => 'SEO 日、周、月数据多维表格，用于自然流量数据同步。',
            'fields' => [
                'seo_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_SEO_APP_TOKEN', 'secret' => true, 'placeholder' => '输入 SEO 多维表格 App Token'],
                'seo_monthly_table_id' => ['label' => '月度表 Table ID', 'env_key' => 'FEISHU_SEO_MONTHLY_TABLE_ID', 'secret' => false, 'placeholder' => '输入月度表 Table ID'],
                'seo_weekly_table_id' => ['label' => '周度表 Table ID', 'env_key' => 'FEISHU_SEO_WEEKLY_TABLE_ID', 'secret' => false, 'placeholder' => '输入周度表 Table ID'],
                'seo_daily_table_id' => ['label' => '日度表 Table ID', 'env_key' => 'FEISHU_SEO_DAILY_TABLE_ID', 'secret' => false, 'placeholder' => '输入日度表 Table ID'],
            ],
        ],
        'seo_work' => [
            'title' => 'SEO 工作推进表',
            'description' => '新博客、旧博客、外链和 AI 自动化工作推进表。',
            'fields' => [
                'seo_work_new_blog_token' => ['label' => '新博客推进表 电子表格 Token', 'env_key' => 'FEISHU_SEO_WORK_NEW_BLOG_TOKEN', 'secret' => true, 'placeholder' => '输入新博客推进表 Token'],
                'seo_work_new_blog_sheet' => ['label' => '新博客推进表 Sheet ID', 'env_key' => 'FEISHU_SEO_WORK_NEW_BLOG_SHEET', 'secret' => false, 'placeholder' => '输入新博客推进表 Sheet ID'],
                'seo_work_old_blog_token' => ['label' => '旧博客推进表 电子表格 Token', 'env_key' => 'FEISHU_SEO_WORK_OLD_BLOG_TOKEN', 'secret' => true, 'placeholder' => '输入旧博客推进表 Token'],
                'seo_work_old_blog_sheet' => ['label' => '旧博客推进表 Sheet ID', 'env_key' => 'FEISHU_SEO_WORK_OLD_BLOG_SHEET', 'secret' => false, 'placeholder' => '输入旧博客推进表 Sheet ID'],
                'seo_work_backlinks_token' => ['label' => '外链推进表 电子表格 Token', 'env_key' => 'FEISHU_SEO_WORK_BACKLINKS_TOKEN', 'secret' => true, 'placeholder' => '输入外链推进表 Token'],
                'seo_work_backlinks_sheet' => ['label' => '外链推进表 Sheet ID', 'env_key' => 'FEISHU_SEO_WORK_BACKLINKS_SHEET', 'secret' => false, 'placeholder' => '输入外链推进表 Sheet ID'],
                'seo_work_ai_token' => ['label' => 'AI 自动化推进表 电子表格 Token', 'env_key' => 'FEISHU_SEO_WORK_AI_TOKEN', 'secret' => true, 'placeholder' => '输入 AI 自动化推进表 Token'],
                'seo_work_ai_sheet' => ['label' => 'AI 自动化推进表 Sheet ID', 'env_key' => 'FEISHU_SEO_WORK_AI_SHEET', 'secret' => false, 'placeholder' => '输入 AI 自动化推进表 Sheet ID'],
            ],
        ],
        'sequence' => [
            'title' => '序列表现',
            'description' => '广告序列表现电子表格（Wiki 节点）。',
            'fields' => [
                'sequence_wiki_node' => ['label' => 'Wiki 节点 Token', 'env_key' => 'FEISHU_SEQUENCE_WIKI_NODE', 'secret' => true, 'placeholder' => '输入广告序列表现 Wiki 节点 Token'],
            ],
        ],
        'social' => [
            'title' => '品牌官媒',
            'description' => '品牌官媒电子表格（Wiki 节点）。',
            'fields' => [
                'social_wiki_node' => ['label' => 'Wiki 节点 Token', 'env_key' => 'FEISHU_SOCIAL_WIKI_NODE', 'secret' => true, 'placeholder' => '输入品牌官媒 Wiki 节点 Token'],
            ],
        ],
        'kol' => [
            'title' => '红人运营',
            'description' => '红人运营、年度车型汇总与爆款视频统计多维表格。',
            'fields' => [
                'kol_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_KOL_APP_TOKEN', 'secret' => true, 'placeholder' => '输入红人运营多维表格 App Token'],
                'kol_table_id' => ['label' => '红人数据表 Table ID', 'env_key' => 'FEISHU_KOL_TABLE_ID', 'secret' => false, 'placeholder' => '输入红人数据表 Table ID'],
                'kol_view_id' => ['label' => '红人数据视图 View ID', 'env_key' => 'FEISHU_KOL_VIEW_ID', 'secret' => false, 'placeholder' => '输入红人数据视图 View ID'],
                'kol_yearly_table_id' => ['label' => '年度车型汇总表 Table ID', 'env_key' => 'FEISHU_KOL_YEARLY_TABLE_ID', 'secret' => false, 'placeholder' => '输入年度车型汇总表 Table ID'],
                'kol_yearly_view_id' => ['label' => '年度汇总视图 View ID', 'env_key' => 'FEISHU_KOL_YEARLY_VIEW_ID', 'secret' => false, 'placeholder' => '输入年度汇总视图 View ID'],
                'kol_viral_table_id' => ['label' => '爆款视频统计表 Table ID', 'env_key' => 'FEISHU_KOL_VIRAL_TABLE_ID', 'secret' => false, 'placeholder' => '输入爆款视频统计表 Table ID'],
                'kol_viral_view_id' => ['label' => '爆款视频视图 View ID', 'env_key' => 'FEISHU_KOL_VIRAL_VIEW_ID', 'secret' => false, 'placeholder' => '输入爆款视频视图 View ID'],
            ],
        ],
        'edm' => [
            'title' => 'EDM 邮件',
            'description' => 'EDM 目标电子表格（Wiki 节点）。',
            'fields' => [
                'edm_wiki_node' => ['label' => 'Wiki 节点 Token', 'env_key' => 'FEISHU_EDM_WIKI_NODE', 'secret' => true, 'placeholder' => '输入 EDM Wiki 节点 Token'],
            ],
        ],
        'affiliate' => [
            'title' => '联盟营销',
            'description' => '联盟营销数据多维表格。',
            'fields' => [
                'affiliate_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_AFFILIATE_APP_TOKEN', 'secret' => true, 'placeholder' => '输入联盟营销多维表格 App Token'],
                'affiliate_table_id' => ['label' => '数据表 Table ID', 'env_key' => 'FEISHU_AFFILIATE_TABLE_ID', 'secret' => false, 'placeholder' => '输入联盟营销数据表 Table ID'],
                'affiliate_view_id' => ['label' => '视图 View ID', 'env_key' => 'FEISHU_AFFILIATE_VIEW_ID', 'secret' => false, 'placeholder' => '输入联盟营销视图 View ID'],
            ],
        ],
        'design' => [
            'title' => '视觉设计',
            'description' => '设计绩效管理多维表格。',
            'fields' => [
                'design_app_token' => ['label' => '多维表格 App Token', 'env_key' => 'FEISHU_DESIGN_APP_TOKEN', 'secret' => true, 'placeholder' => '输入视觉设计多维表格 App Token'],
                'design_table_id' => ['label' => '数据表 Table ID', 'env_key' => 'FEISHU_DESIGN_TABLE_ID', 'secret' => false, 'placeholder' => '输入视觉设计数据表 Table ID'],
            ],
        ],
        'reputation' => [
            'title' => '舆情监控',
            'description' => '口碑管理与 Reddit / Threads 数据源电子表格（Wiki 节点）。',
            'fields' => [
                'reputation_wiki_node' => ['label' => '口碑管理 Wiki 节点 Token', 'env_key' => 'FEISHU_REPUTATION_WIKI_NODE', 'secret' => true, 'placeholder' => '输入口碑管理 Wiki 节点 Token'],
                'reddit_wiki_node' => ['label' => 'Reddit Wiki 节点 Token', 'env_key' => 'FEISHU_REDDIT_WIKI_NODE', 'secret' => true, 'placeholder' => '输入 Reddit Wiki 节点 Token'],
                'threads_wiki_node' => ['label' => 'Threads Wiki 节点 Token', 'env_key' => 'FEISHU_THREADS_WIKI_NODE', 'secret' => true, 'placeholder' => '留空则复用 Reddit Wiki 节点 Token'],
            ],
        ],
    ];

    /** @return array<int, array<string, mixed>> */
    public function catalogForFrontend(Store $store, bool $canReveal = false): array
    {
        $credentials = $this->credentials($store);

        return collect(self::SECTIONS)
            ->map(function (array $section, string $sectionKey) use ($credentials, $canReveal): array {
                $fields = collect($section['fields'])->map(function (array $field, string $fieldKey) use ($credentials, $canReveal): array {
                    $credential = $credentials->get($fieldKey);

                    return [
                        'key' => $fieldKey,
                        'label' => $field['label'],
                        'env_key' => $field['env_key'],
                        'secret' => $field['secret'],
                        'placeholder' => $field['placeholder'],
                        'configured' => $credential !== null,
                        'masked_value' => $credential && $canReveal ? $this->mask($credential->credential_value) : '',
                        'current_value' => $credential && ! $field['secret'] ? $credential->credential_value : '',
                    ];
                })->values()->all();

                return [
                    'key' => $sectionKey,
                    'title' => $section['title'],
                    'description' => $section['description'],
                    'configured' => collect($fields)->contains(fn (array $field): bool => $field['configured']),
                    'fields' => $fields,
                ];
            })
            ->values()
            ->all();
    }

    public function reveal(Store $store, string $sectionKey, string $fieldKey): string
    {
        $this->fieldDefinition($sectionKey, $fieldKey);

        return $this->credential($store, $fieldKey)?->credential_value ?? '';
    }

    /**
     * Internal background-sync entry point. Values remain scoped to the
     * supplied store and must never be returned in an HTTP response.
     *
     * @return array<string, string>
     */
    public function valuesForSync(Store $store, string $sectionKey): array
    {
        $section = $this->sectionDefinition($sectionKey);
        $allowedKeys = array_keys($section['fields']);

        return $this->credentials($store)
            ->filter(fn (StoreBusinessCredential $credential, string $key): bool => in_array($key, $allowedKeys, true))
            ->map(fn (StoreBusinessCredential $credential): string => $credential->credential_value)
            ->all();
    }

    /** @return array{schema: string, section: string, configured: bool, has_configuration: bool, missing_fields: list<string>} */
    public function sectionStatusForFrontend(Store $store, string $sectionKey): array
    {
        $section = $this->sectionDefinition($sectionKey);
        $fieldKeys = array_keys($section['fields']);
        $configuredKeys = $this->credentials($store)
            ->filter(fn (StoreBusinessCredential $credential, string $key): bool => in_array($key, $fieldKeys, true)
                && filled($credential->credential_value))
            ->keys()
            ->all();
        $missingFields = array_values(array_diff($fieldKeys, $configuredKeys));

        return [
            'schema' => 'feishu-data-link-status-v1',
            'section' => $sectionKey,
            'configured' => $missingFields === [],
            'has_configuration' => $configuredKeys !== [],
            'missing_fields' => $missingFields,
        ];
    }

    /** @param array<string, mixed> $values */
    public function updateSection(Store $store, string $sectionKey, array $values, User $actor): void
    {
        $section = $this->sectionDefinition($sectionKey);
        $allowedKeys = array_keys($section['fields']);
        $changedKeys = [];

        DB::transaction(function () use ($store, $sectionKey, $values, $actor, $allowedKeys, &$changedKeys): void {
            foreach ($allowedKeys as $fieldKey) {
                $value = trim((string) ($values[$fieldKey] ?? ''));

                if ($value === '') {
                    continue;
                }

                StoreBusinessCredential::query()->updateOrCreate(
                    [
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'provider' => self::PROVIDER,
                        'credential_key' => $fieldKey,
                    ],
                    [
                        'credential_value' => $value,
                        'updated_by' => $actor->id,
                    ],
                );
                $changedKeys[] = $fieldKey;
            }

            if ($changedKeys === []) {
                return;
            }

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'store_feishu_data_links_updated',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'old_values' => null,
                'new_values' => ['configured' => true],
                'metadata' => [
                    'scope' => 'store',
                    'section' => $sectionKey,
                    'changed_keys' => $changedKeys,
                ],
            ]);
        });
    }

    public function clearSection(Store $store, string $sectionKey, User $actor): int
    {
        $section = $this->sectionDefinition($sectionKey);
        $allowedKeys = array_keys($section['fields']);

        return DB::transaction(function () use ($store, $sectionKey, $actor, $allowedKeys): int {
            $deleted = StoreBusinessCredential::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('provider', self::PROVIDER)
                ->whereIn('credential_key', $allowedKeys)
                ->delete();

            if ($deleted === 0) {
                return 0;
            }

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'store_feishu_data_links_cleared',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'old_values' => ['configured' => true],
                'new_values' => ['configured' => false],
                'metadata' => [
                    'scope' => 'store',
                    'section' => $sectionKey,
                    'cleared_keys' => $allowedKeys,
                ],
            ]);

            return $deleted;
        });
    }

    /** @param list<string> $credentialKeys */
    public function clearCredentialKeys(Store $store, array $credentialKeys): int
    {
        if ($credentialKeys === []) {
            return 0;
        }

        return StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('provider', self::PROVIDER)
            ->whereIn('credential_key', $credentialKeys)
            ->delete();
    }

    /** @return array{title: string, description: string, fields: array<string, array{label: string, env_key: string, secret: bool, placeholder: string}>} */
    public function sectionDefinition(string $sectionKey): array
    {
        abort_unless(isset(self::SECTIONS[$sectionKey]), 404);

        return self::SECTIONS[$sectionKey];
    }

    /** @return array{label: string, env_key: string, secret: bool, placeholder: string} */
    public function fieldDefinition(string $sectionKey, string $fieldKey): array
    {
        $section = $this->sectionDefinition($sectionKey);
        abort_unless(isset($section['fields'][$fieldKey]), 404);

        return $section['fields'][$fieldKey];
    }

    /** @return Collection<string, StoreBusinessCredential> */
    private function credentials(Store $store): Collection
    {
        return StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('provider', self::PROVIDER)
            ->get()
            ->keyBy('credential_key');
    }

    private function credential(Store $store, string $fieldKey): ?StoreBusinessCredential
    {
        return StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('provider', self::PROVIDER)
            ->where('credential_key', $fieldKey)
            ->first();
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 8) {
            return str_repeat('•', max(4, $length));
        }

        return mb_substr($value, 0, 3).'***'.mb_substr($value, -3);
    }
}
