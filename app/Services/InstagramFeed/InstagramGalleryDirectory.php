<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramGallery;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 主题编辑器里的展示组选择器目录。
 *
 * 区块设置的 schema 是构建期静态 JSON，一个 App 版本服务所有店铺，所以没法把某个
 * 店铺自己的组名列成 select 的 options。Shopify 官方唯一支持「按店铺动态取选项」的
 * 设置类型是 metaobject：App 用保留前缀 `$app:` 建一个自己独占的 definition，把每个
 * 展示组写成一条条目，区块里声明
 * `{"type": "metaobject", "metaobject_type": "$app:instagram_gallery"}`
 * 就能在主题编辑器里得到一个直接显示组名的选择器，商家不用手打任何字。
 *
 * 两个前提缺一不可，否则主题编辑器会把那个设置直接显示成错误：
 *   1. definition 必须已经存在于店铺上；
 *   2. definition 必须对 storefront 可读（`access.storefront = PUBLIC_READ`），
 *      否则主题渲染时读不到条目。
 * 因此部署顺序不能颠倒：先让后端把 definition 建好、条目补齐，再发布带该设置的扩展。
 *
 * 这里的任何失败都不能推翻调用方已经完成的动作 —— 展示组本身已经改了、前台数据也已
 * 经发布，选择器目录只是主题编辑器里的便利。同理 `write_metaobjects` 刻意不进
 * `config('instagram_feed.required_scopes')`：那个校验在每次建立内嵌会话时都会跑，
 * 加进去会让尚未重新授权的店铺连应用都打不开。
 */
class InstagramGalleryDirectory
{
    private const DEFINITION_BY_TYPE_QUERY = <<<'GRAPHQL'
    query InstagramGalleryDefinition($type: String!) {
      metaobjectDefinitionByType(type: $type) {
        id
        type
        access { storefront }
        fieldDefinitions { key }
      }
    }
    GRAPHQL;

    private const DEFINITION_CREATE_MUTATION = <<<'GRAPHQL'
    mutation InstagramGalleryDefinitionCreate($definition: MetaobjectDefinitionCreateInput!) {
      metaobjectDefinitionCreate(definition: $definition) {
        metaobjectDefinition { id type }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    private const DEFINITION_UPDATE_MUTATION = <<<'GRAPHQL'
    mutation InstagramGalleryDefinitionUpdate($id: ID!, $definition: MetaobjectDefinitionUpdateInput!) {
      metaobjectDefinitionUpdate(id: $id, definition: $definition) {
        metaobjectDefinition { id access { storefront } }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    private const ENTRIES_QUERY = <<<'GRAPHQL'
    query InstagramGalleryEntries($type: String!, $after: String) {
      metaobjects(type: $type, first: 250, after: $after) {
        nodes { id handle }
        pageInfo { hasNextPage endCursor }
      }
    }
    GRAPHQL;

    private const ENTRY_UPSERT_MUTATION = <<<'GRAPHQL'
    mutation InstagramGalleryEntryUpsert($handle: MetaobjectHandleInput!, $metaobject: MetaobjectUpsertInput!) {
      metaobjectUpsert(handle: $handle, metaobject: $metaobject) {
        metaobject { id handle }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    private const ENTRY_DELETE_MUTATION = <<<'GRAPHQL'
    mutation InstagramGalleryEntryDelete($id: ID!) {
      metaobjectDelete(id: $id) {
        deletedId
        userErrors { field message code }
      }
    }
    GRAPHQL;

    public function __construct(private InstagramFeedShopifyClient $client) {}

    /**
     * 把店铺当前的展示组对齐到 Shopify 的 metaobject 条目。
     *
     * 全量对齐而不是增量：条目可能被商家在 Shopify 后台手工删掉、也可能因为上一次同步
     * 中途失败而残留。每次都按当前展示组重建一遍，比维护一份增量状态可靠。
     *
     * @return array{galleries: int, upserted: int, deleted: int}
     */
    public function sync(Store $store, ?User $actor = null): array
    {
        $this->ensureDefinition($store);

        $type = $this->definitionType();
        $galleries = InstagramGallery::query()
            ->where('store_id', $store->id)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        $existing = $this->existingEntries($store, $type);
        $upserted = 0;
        foreach ($galleries as $gallery) {
            $this->upsertEntry($store, $type, $gallery);
            unset($existing[$gallery->handle]);
            $upserted++;
        }

        // 剩下的是已经删掉的组留下的条目。留着会让主题编辑器的选择器出现选不出内容的项。
        $deleted = 0;
        foreach ($existing as $entryId) {
            $this->deleteEntry($store, $entryId);
            $deleted++;
        }

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => 'instagram_feed_gallery_directory_synced',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                'type' => $type,
                'galleries' => $galleries->count(),
                'upserted' => $upserted,
                'deleted' => $deleted,
            ],
        ]);

        return ['galleries' => $galleries->count(), 'upserted' => $upserted, 'deleted' => $deleted];
    }

    /**
     * 供写操作串在主动作后面调用：成功返回空串，失败返回可拼进提示的原因。
     *
     * 与 publisher 的 syncStorefront 一样，失败不抛 —— 主动作已经生效了，不能因为
     * 选择器目录没同步上就让商家以为展示组没改成。
     */
    public function syncQuietly(Store $store): string
    {
        try {
            $this->sync($store, null);

            return '';
        } catch (InstagramFeedException $exception) {
            return ' 但主题编辑器的展示组选项未更新：'.$exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return ' 但主题编辑器的展示组选项未更新，请稍后重试。';
        }
    }

    /**
     * 确保 definition 存在且对 storefront 可读。
     *
     * 幂等：已存在就只补 storefront 访问权限。`$app:` 前缀由 Shopify 解析成
     * `app--<app-id>--instagram_gallery`，归本 App 独占，商家在后台看不到也改不了结构。
     */
    public function ensureDefinition(Store $store): string
    {
        $type = $this->definitionType();
        $existing = $this->client->graphql($store, self::DEFINITION_BY_TYPE_QUERY, ['type' => $type]);
        $definitionId = data_get($existing, 'data.metaobjectDefinitionByType.id');

        if (is_string($definitionId) && $definitionId !== '') {
            $storefrontAccess = (string) data_get($existing, 'data.metaobjectDefinitionByType.access.storefront');
            if ($storefrontAccess !== 'PUBLIC_READ') {
                // 早期建出来的 definition 可能没开 storefront 读，主题里就读不到条目。
                $this->client->graphql($store, self::DEFINITION_UPDATE_MUTATION, [
                    'id' => $definitionId,
                    'definition' => ['access' => ['storefront' => 'PUBLIC_READ']],
                ]);
            }

            return $definitionId;
        }

        $created = $this->client->graphql($store, self::DEFINITION_CREATE_MUTATION, [
            'definition' => [
                'type' => $type,
                'name' => (string) config('instagram_feed.metaobject.name', 'Instagram gallery'),
                // 主题编辑器的选择器按 displayNameKey 显示条目，所以这里必须指向组名，
                // 否则商家看到的是随机 handle，等于回到手抄标识那个老问题。
                'displayNameKey' => $this->nameField(),
                'access' => ['storefront' => 'PUBLIC_READ'],
                'fieldDefinitions' => [
                    [
                        'key' => $this->nameField(),
                        'name' => 'Name',
                        'type' => 'single_line_text_field',
                        'required' => true,
                    ],
                    [
                        'key' => $this->handleField(),
                        'name' => 'Handle',
                        'type' => 'single_line_text_field',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $this->assertNoUserErrors($created, 'data.metaobjectDefinitionCreate.userErrors', 'GALLERY_DIRECTORY_DEFINITION_FAILED');
        $definitionId = data_get($created, 'data.metaobjectDefinitionCreate.metaobjectDefinition.id');
        if (! is_string($definitionId) || $definitionId === '') {
            throw new InstagramFeedException(
                'GALLERY_DIRECTORY_DEFINITION_FAILED',
                'Shopify 未能创建展示组选择器所需的数据结构。',
                502,
            );
        }

        return $definitionId;
    }

    /** `$app:` 会被 Shopify 解析成 `app--<app-id>--<type>`，不要自己拼 app id。 */
    public function definitionType(): string
    {
        return '$app:'.(string) config('instagram_feed.metaobject.type', 'instagram_gallery');
    }

    private function nameField(): string
    {
        return (string) config('instagram_feed.metaobject.name_field', 'gallery_name');
    }

    /**
     * 匹配用字段刻意不叫 handle：metaobject 对象在 Liquid 里本身就有 system.handle，
     * 同名字段读起来容易搞混，也容易被误当成内置属性。
     */
    private function handleField(): string
    {
        return (string) config('instagram_feed.metaobject.handle_field', 'gallery_handle');
    }

    /**
     * 取店铺上现有的条目，返回 handle => id。
     *
     * @return array<string, string>
     */
    private function existingEntries(Store $store, string $type): array
    {
        $entries = [];
        $after = null;

        // 展示组数量很少，但条目可能因历史同步残留，所以仍按游标翻到底。
        do {
            $payload = $this->client->graphql($store, self::ENTRIES_QUERY, [
                'type' => $type,
                'after' => $after,
            ]);

            foreach ((array) data_get($payload, 'data.metaobjects.nodes', []) as $node) {
                $handle = data_get($node, 'handle');
                $id = data_get($node, 'id');
                if (is_string($handle) && is_string($id) && $handle !== '' && $id !== '') {
                    $entries[$handle] = $id;
                }
            }

            $hasNext = (bool) data_get($payload, 'data.metaobjects.pageInfo.hasNextPage');
            $after = data_get($payload, 'data.metaobjects.pageInfo.endCursor');
        } while ($hasNext && is_string($after) && $after !== '');

        return $entries;
    }

    private function upsertEntry(Store $store, string $type, InstagramGallery $gallery): void
    {
        // 条目 handle 直接用展示组的 handle：它在店铺内已经唯一，主题里也就能用
        // system.handle 反查回同一个组，不需要再存一份映射。
        $payload = $this->client->graphql($store, self::ENTRY_UPSERT_MUTATION, [
            'handle' => ['type' => $type, 'handle' => $gallery->handle],
            'metaobject' => [
                'fields' => [
                    ['key' => $this->nameField(), 'value' => $gallery->name],
                    ['key' => $this->handleField(), 'value' => $gallery->handle],
                ],
            ],
        ]);

        $this->assertNoUserErrors($payload, 'data.metaobjectUpsert.userErrors', 'GALLERY_DIRECTORY_UPSERT_FAILED');
    }

    private function deleteEntry(Store $store, string $entryId): void
    {
        $payload = $this->client->graphql($store, self::ENTRY_DELETE_MUTATION, ['id' => $entryId]);

        // 删除失败不值得中断整轮同步：残留一条选不出内容的选项，比让已经改好的
        // 组名同步不上去要轻。
        $errors = data_get($payload, 'data.metaobjectDelete.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            Log::warning('Instagram Feed 展示组选项条目删除失败。', [
                'shop' => $store->shopify_domain,
                'entry_id' => $entryId,
                'errors' => $errors,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertNoUserErrors(array $payload, string $path, string $code): void
    {
        $errors = data_get($payload, $path, []);
        if (! is_array($errors) || $errors === []) {
            return;
        }

        $message = collect($errors)
            ->map(fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : (string) $error)
            ->filter()
            ->implode('；');

        throw new InstagramFeedException($code, $message === '' ? 'Shopify 拒绝了展示组选项的写入。' : $message, 502);
    }
}
