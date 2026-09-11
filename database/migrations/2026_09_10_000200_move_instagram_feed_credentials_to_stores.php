<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram Feed 的应用配置从平台级搬到店铺级。
 *
 * 商家现在在 Shopify App 内嵌页里维护自己的 Meta 应用凭证与 R2 存储凭证，
 * DecoAdmin 后台不再提供编辑入口。原来存在 system_settings 的那一份是所有店铺共享的，
 * 直接删掉会让已经在用的店铺立刻失去凭证，所以先按店铺复制一份，再清掉平台级。
 *
 * 复制范围：已经连过 Instagram 账号、或者装过本 App 的店铺。没碰过这个功能的店铺
 * 不需要凭证，留空即可（商家自己去填）。
 *
 * 同时把只服务于后台写操作的 4 个权限软删除 —— 后台已经没有任何写入口，
 * 留着会让管理员在角色页面看到能勾选但完全不起作用的权限。
 * PermissionSeeder 是按 slug 增量 upsert 的，不再列出即保持软删除状态；
 * 需要回滚时 down() 会把它们恢复。
 */
return new class extends Migration
{
    /** @var list<string> */
    private const RETIRED_PERMISSIONS = [
        'instagram_feed.connect',
        'instagram_feed.sync',
        'instagram_feed.gallery.manage',
        'instagram_feed.publish',
    ];

    public function up(): void
    {
        $this->copyPlatformCredentialsToStores();

        DB::table('system_settings')
            ->whereIn('section', ['instagram_meta', 'instagram_r2'])
            ->delete();

        DB::table('permissions')
            ->whereIn('slug', self::RETIRED_PERMISSIONS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        // 平台级凭证不还原：明文已经按店铺存好了，再写回一份共享副本只会造成两处真源。
        DB::table('permissions')
            ->whereIn('slug', self::RETIRED_PERMISSIONS)
            ->update(['deleted_at' => null]);
    }

    private function copyPlatformCredentialsToStores(): void
    {
        if (! Schema::hasTable('instagram_feed_store_settings') || ! Schema::hasTable('system_settings')) {
            return;
        }

        $meta = $this->section('instagram_meta');
        $r2 = $this->section('instagram_r2');
        if ($meta === [] && $r2 === []) {
            return;
        }

        foreach ($this->storesUsingInstagramFeed() as $store) {
            $exists = DB::table('instagram_feed_store_settings')->where('store_id', $store->id)->exists();
            if ($exists) {
                continue;
            }

            DB::table('instagram_feed_store_settings')->insert([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'instagram_app_id' => $this->plain($meta, 'instagram_app_id'),
                'instagram_app_secret' => $this->secret($meta, 'instagram_app_secret'),
                'facebook_app_id' => $this->plain($meta, 'facebook_app_id'),
                'facebook_app_secret' => $this->secret($meta, 'facebook_app_secret'),
                'facebook_login_config_id' => $this->plain($meta, 'facebook_login_config_id'),
                'r2_account_id' => $this->plain($r2, 'account_id'),
                'r2_access_key_id' => $this->plain($r2, 'access_key_id'),
                'r2_secret_access_key' => $this->secret($r2, 'secret_access_key'),
                'r2_bucket' => $this->plain($r2, 'bucket'),
                'r2_public_base_url' => $this->plain($r2, 'public_base_url'),
                'updated_by' => null,
                'updated_from' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function storesUsingInstagramFeed(): \Illuminate\Support\Collection
    {
        $storeIds = collect();
        foreach (['instagram_accounts', 'instagram_feed_installations'] as $table) {
            if (Schema::hasTable($table)) {
                $storeIds = $storeIds->merge(DB::table($table)->pluck('store_id'));
            }
        }

        $storeIds = $storeIds->filter()->unique()->values();
        if ($storeIds->isEmpty()) {
            return collect();
        }

        return DB::table('stores')
            ->whereIn('id', $storeIds->all())
            ->get(['id', 'organization_id']);
    }

    /** @return array<string, string> */
    private function section(string $section): array
    {
        return DB::table('system_settings')
            ->where('section', $section)
            ->get(['id', 'key', 'value'])
            ->mapWithKeys(function (object $row) use ($section): array {
                try {
                    $decoded = json_decode(Crypt::decryptString((string) $row->value), true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $exception) {
                    // 换过 APP_KEY 的部署解不开旧值，跳过即可：商家在应用里重填一次。
                    Log::warning('Instagram Feed platform credential could not be migrated and was skipped.', [
                        'section' => $section,
                        'key' => (string) $row->key,
                        'reason' => $exception->getMessage(),
                    ]);

                    return [];
                }

                return is_scalar($decoded) ? [(string) $row->key => trim((string) $decoded)] : [];
            })
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    /** @param array<string, string> $values */
    private function plain(array $values, string $key): ?string
    {
        $value = $values[$key] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * 新表的密钥列走 Eloquent 的 encrypted cast，这里用 Query Builder 直接插，
     * 所以要自己加密成同一种格式。
     *
     * @param  array<string, string>  $values
     */
    private function secret(array $values, string $key): ?string
    {
        $value = $values[$key] ?? '';

        return $value === '' ? null : Crypt::encryptString($value);
    }
};
