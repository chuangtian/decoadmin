<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use App\Services\Feishu\FeishuBitableClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BrandProfileService
{
    private const SOURCE = 'brand_profile';

    private const SECTIONS = [
        'overview' => ['title' => '总览', 'columns' => ['项目', '类型', '说明', '链接', '负责人/对接人', '其他']],
        'login-emails' => ['title' => '登录邮箱', 'columns' => ['账号', '密码', '备注', '备注2']],
        'seo-accounts' => ['title' => 'SEO账号密码', 'columns' => ['平台', '登陆URL', '账号', '密码']],
        'plugins' => ['title' => '插件', 'columns' => ['插件名称', '账号', '密码', '验证', '负责人/对接人', '备注', '收费情况']],
        'business-licenses' => ['title' => '营业执照', 'columns' => []],
    ];

    public function __construct(private FeishuBitableClient $client, private StoreFeishuDataLinkService $links) {}

    public function page(Store $store, string $section): array
    {
        $definition = self::SECTIONS[$section];
        $source = $this->source($store);
        $table = $this->snapshot($store, $section);
        $data = $table?->metadata_encrypted ?? [];
        $replacement = $section === 'business-licenses' ? $this->licenseImage($store) : null;
        if ($replacement) {
            $assets = $data['assets'] ?? [];
            $imageIndex = collect($assets)->search(fn (array $asset): bool => $asset['kind'] === 'image');
            if ($imageIndex === false) {
                array_unshift($assets, $replacement->metadata_encrypted);
            } else {
                $assets[$imageIndex] = $replacement->metadata_encrypted;
            }
            $data['assets'] = $assets;
        }
        $rows = array_map(function (array $row): array {
            foreach ($row['cells'] as &$cell) {
                if ($cell['secret'] ?? false) {
                    $cell = ['secret' => true, 'configured' => $cell['configured'], 'text' => '', 'links' => []];
                }
            }

            return $row;
        }, $data['rows'] ?? []);

        return [
            'configured' => $source['configured'] || $replacement !== null,
            'inherited' => $source['source_store_id'] !== (int) $store->id,
            'source_url' => $source['url'],
            'synced_at' => collect([$table?->synced_at, $replacement?->synced_at])->filter()->sortDesc()->first()?->toIso8601String(),
            'columns' => $definition['columns'],
            'rows' => $rows,
            'assets' => array_map(fn (array $asset): array => [
                'id' => $asset['id'], 'name' => $asset['name'], 'kind' => $asset['kind'],
                'url' => '/brand-profile/'.$store->id.'/files/'.$asset['id'],
            ], $data['assets'] ?? []),
        ];
    }

    public function sync(Store $store): array
    {
        $source = $this->source($store);
        $sourceStore = (int) $source['source_store_id'] === (int) $store->id
            ? $store
            : Store::query()
                ->where('organization_id', $store->organization_id)
                ->where('status', 'active')
                ->findOrFail($source['source_store_id']);

        $result = Cache::lock('brand-profile-sync:'.$store->organization_id.':'.$sourceStore->id, 180)->get(function () use ($source, $sourceStore): array {
            if (! $source['configured']) {
                throw new RuntimeException('请先在店铺飞书设置中配置品牌资料原表。');
            }
            $token = $source['token'];
            if ($token === '') {
                $node = $this->client->wikiNode($source['wiki_node']);
                if (($node['obj_type'] ?? 'sheet') !== 'sheet') {
                    throw new RuntimeException('品牌资料原表必须是飞书电子表格。');
                }
                $token = (string) ($node['obj_token'] ?? '');
            }

            $sheets = $this->client->spreadsheetSheets($token);
            $incoming = [];
            foreach (self::SECTIONS as $key => $definition) {
                $sheet = collect($sheets)->first(fn (array $sheet): bool => $this->normalized($sheet['title'] ?? '') === $this->normalized($definition['title']));
                if (! $sheet) {
                    throw new RuntimeException('品牌资料原表缺少“'.$definition['title'].'”工作表，已保留上次同步内容。');
                }
                $rowCount = (int) data_get($sheet, 'grid_properties.row_count', 200);
                if ($rowCount > 5000) {
                    throw new RuntimeException('品牌资料工作表超过 5000 行，请整理原表后再同步。');
                }
                $values = $this->client->spreadsheetValues($token, $sheet['sheet_id'], max(1, $rowCount), min(50, max(1, (int) data_get($sheet, 'grid_properties.column_count', 20))));
                $incoming[$key] = $this->parse($sourceStore, $key, $values);
                $incoming[$key]['source_fingerprint'] = $source['fingerprint'];
            }

            return DB::transaction(function () use ($sourceStore, $incoming): array {
                $counts = [];
                foreach ($incoming as $section => $data) {
                    FeishuBitableTable::query()->updateOrCreate([
                        'organization_id' => $sourceStore->organization_id, 'store_id' => $sourceStore->id,
                        'source_section' => self::SOURCE, 'source_table_id' => $section,
                    ], [
                        'name' => self::SECTIONS[$section]['title'],
                        'metadata_encrypted' => $data, 'synced_at' => now(),
                    ]);
                    $counts[$section] = count($data['rows']) + count($data['assets']);
                }

                return $counts;
            });
        });

        if ($result === false) {
            throw new RuntimeException('品牌资料正在同步，请稍后刷新页面。');
        }

        return $result;
    }

    public function password(Store $store, string $section, string $rowId): string
    {
        abort_unless(in_array($section, ['login-emails', 'seo-accounts', 'plugins'], true), 404);
        $data = $this->snapshot($store, $section)?->metadata_encrypted ?? [];
        $row = collect($data['rows'] ?? [])->firstWhere('id', $rowId);
        abort_unless($row, 404);
        $cell = $row['cells']['密码'] ?? [];
        abort_unless(($cell['secret'] ?? false) && ($cell['configured'] ?? false), 404);

        return $cell['value'];
    }

    public function file(Store $store, string $assetId): array
    {
        $replacementTable = $this->licenseImage($store);
        $replacement = $replacementTable?->metadata_encrypted;
        if ($replacement && hash_equals($replacement['id'], $assetId)) {
            $path = $this->licenseImagePathForScope(
                (int) $replacementTable->organization_id,
                (int) $replacementTable->store_id,
                $replacement,
            );
            abort_unless(Storage::disk('local')->exists($path), 404);

            return ['contents' => Storage::disk('local')->get($path), 'mime' => $replacement['mime'], 'name' => $replacement['name']];
        }
        $data = $this->snapshot($store, 'business-licenses')?->metadata_encrypted ?? [];
        $asset = collect($data['assets'] ?? [])->firstWhere('id', $assetId);
        abort_unless($asset, 404);
        $download = $this->client->downloadMedia($asset['token']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($download['contents']);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true), 415, '暂不支持预览该文件类型。');
        abort_if(strlen($download['contents']) > 50 * 1024 * 1024, 413, '附件超过预览大小限制。');

        return ['contents' => $download['contents'], 'mime' => $mime, 'name' => $asset['name']];
    }

    public function replaceLicenseImage(Store $store, string $contents): array
    {
        if (strlen($contents) > 10 * 1024 * 1024) {
            throw new RuntimeException('营业执照图片不能超过 10 MB。');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $extension || @getimagesizefromstring($contents) === false) {
            throw new RuntimeException('请提供有效的 PNG、JPEG 或 WebP 图片。');
        }
        $asset = [
            'id' => hash_hmac('sha256', $store->id.'|uploaded-license|'.hash('sha256', $contents), config('app.key')),
            'name' => '营业执照图片.'.$extension, 'kind' => 'image', 'mime' => $mime, 'extension' => $extension,
            'sha256' => hash('sha256', $contents),
        ];
        // Store the original bytes privately; refreshing Feishu must not undo a user replacement.
        if (! Storage::disk('local')->put($this->licenseImagePath($store, $asset), $contents, 'private')) {
            throw new RuntimeException('营业执照图片保存失败。');
        }
        DB::transaction(function () use ($store, $asset): void {
            $previous = $this->licenseImage($store)?->metadata_encrypted;
            FeishuBitableTable::query()->updateOrCreate([
                'organization_id' => $store->organization_id, 'store_id' => $store->id,
                'source_section' => 'brand_profile_overrides', 'source_table_id' => 'business-license-image',
            ], ['name' => '营业执照图片', 'metadata_encrypted' => $asset, 'synced_at' => now()]);
            AuditLog::query()->create([
                'organization_id' => $store->organization_id, 'store_id' => $store->id,
                'action' => 'brand_profile_license_image_replaced', 'subject_type' => Store::class, 'subject_id' => $store->id,
                'metadata' => ['origin' => 'user_requested_upload', 'previous_asset_id' => $previous['id'] ?? null, 'asset_id' => $asset['id']],
            ]);
        });

        return ['id' => $asset['id'], 'sha256' => $asset['sha256']];
    }

    private function licenseImage(Store $store): ?FeishuBitableTable
    {
        $replacement = FeishuBitableTable::query()->forOrganization($store->organization_id)->forStore($store->id)
            ->where('source_section', 'brand_profile_overrides')->where('source_table_id', 'business-license-image')->first();

        if ($replacement) {
            return $replacement;
        }

        $sourceStoreId = $this->links->sourceStoreIdForSection($store, 'brand');
        if ($sourceStoreId === (int) $store->id) {
            return null;
        }

        return FeishuBitableTable::query()->forOrganization($store->organization_id)->forStore($sourceStoreId)
            ->where('source_section', 'brand_profile_overrides')->where('source_table_id', 'business-license-image')->first();
    }

    private function licenseImagePath(Store $store, array $asset): string
    {
        return $this->licenseImagePathForScope((int) $store->organization_id, (int) $store->id, $asset);
    }

    private function licenseImagePathForScope(int $organizationId, int $storeId, array $asset): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $asset['id']) && in_array($asset['extension'], ['png', 'jpg', 'webp'], true), 404);

        return 'brand-profile/'.$organizationId.'/'.$storeId.'/licenses/'.$asset['id'].'.'.$asset['extension'];
    }

    private function snapshot(Store $store, string $section): ?FeishuBitableTable
    {
        $source = $this->source($store);
        $table = FeishuBitableTable::query()->forOrganization($store->organization_id)->forStore($store->id)
            ->where('source_section', self::SOURCE)->where('source_table_id', $section)->first();
        if ($table && data_get($table->metadata_encrypted, 'source_fingerprint') === $source['fingerprint']) {
            return $table;
        }

        if ($source['source_store_id'] === (int) $store->id) {
            return null;
        }

        $shared = FeishuBitableTable::query()->forOrganization($store->organization_id)->forStore($source['source_store_id'])
            ->where('source_section', self::SOURCE)->where('source_table_id', $section)->first();

        return $shared && data_get($shared->metadata_encrypted, 'source_fingerprint') === $source['fingerprint']
            ? $shared
            : null;
    }

    private function source(Store $store): array
    {
        $values = $this->links->valuesForSync($store, 'brand');
        $token = trim((string) ($values['brand_spreadsheet_token'] ?? ''));
        $url = $this->safeUrl($values['brand_wiki_url'] ?? '');
        $parts = parse_url($url ?? '');
        $host = strtolower($parts['host'] ?? '');
        $trusted = str_ends_with($host, '.feishu.cn') || str_ends_with($host, '.larksuite.com');
        $wikiNode = $trusted && preg_match('#^/wiki/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $match) ? $match[1] : '';
        if ($token === '' && $trusted && preg_match('#^/sheets/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $match)) {
            $token = $match[1];
        }

        return [
            'token' => $token, 'wiki_node' => $wikiNode, 'url' => $trusted ? $url : null,
            'configured' => $token !== '' || $wikiNode !== '',
            'source_store_id' => $this->links->sourceStoreIdForSection($store, 'brand'),
            'fingerprint' => hash('sha256', $token.'|'.$wikiNode.'|'.($trusted ? $url : '')),
        ];
    }

    private function parse(Store $store, string $section, array $values): array
    {
        if ($section === 'business-licenses') {
            return ['rows' => [], 'assets' => $this->assets($store, $values)];
        }
        $columns = self::SECTIONS[$section]['columns'];
        $headerIndex = null;
        $positions = [];
        foreach (array_slice($values, 0, 20, true) as $index => $row) {
            $headers = array_map(fn ($cell): string => $this->normalized($this->cell($cell)['text']), $row);
            $candidate = [];
            foreach ($columns as $column) {
                $position = array_search($this->normalized($column), $headers, true);
                if ($position !== false) {
                    $candidate[$column] = $position;
                }
            }
            if (count($candidate) === count($columns)) {
                $headerIndex = $index;
                $positions = $candidate;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException('“'.self::SECTIONS[$section]['title'].'”表头不完整，请检查原表列名。');
        }
        $rows = [];
        foreach (array_slice($values, $headerIndex + 1, null, true) as $index => $row) {
            $cells = [];
            $hasContent = false;
            foreach ($positions as $column => $position) {
                $cell = $this->cell($row[$position] ?? null);
                $hasContent = $hasContent || $cell['text'] !== '';
                if ($column === '密码') {
                    $value = $this->secretValue($row[$position] ?? null);
                    $cell = ['secret' => true, 'configured' => $value !== '' && ! preg_match('/^[\s—–\-*•·\/]+$/u', $value), 'value' => $value];
                }
                $cells[$column] = $cell;
            }
            if ($hasContent) {
                $rows[] = ['id' => hash_hmac('sha256', $store->id.'|'.$section.'|'.$index.'|'.json_encode($cells), config('app.key')), 'cells' => $cells];
            }
        }

        return ['rows' => $rows, 'assets' => []];
    }

    private function cell(mixed $value): array
    {
        if (is_scalar($value)) {
            $text = trim((string) $value);

            return ['text' => $text, 'links' => ($url = $this->safeUrl($text)) ? [['text' => $text, 'url' => $url]] : []];
        }
        if (! is_array($value)) {
            return ['text' => '', 'links' => []];
        }
        if (array_is_list($value)) {
            $cells = array_map($this->cell(...), $value);

            return ['text' => implode('', array_column($cells, 'text')), 'links' => array_merge(...array_column($cells, 'links'))];
        }
        if (in_array($value['type'] ?? '', ['embed-image', 'attachment'], true)) {
            return ['text' => '', 'links' => []];
        }
        $text = (string) ($value['text'] ?? $value['name'] ?? '');
        $url = $this->safeUrl($value['link'] ?? $value['url'] ?? '');

        return ['text' => $text, 'links' => $url ? [['text' => $text ?: $url, 'url' => $url]] : []];
    }

    private function secretValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return '';
        }

        return array_is_list($value) ? implode('', array_map($this->secretValue(...), $value)) : (string) ($value['text'] ?? '');
    }

    private function assets(Store $store, array $values): array
    {
        $assets = [];
        $walk = function (mixed $node) use (&$walk, &$assets, $store): void {
            if (! is_array($node)) {
                return;
            }
            if (in_array($node['type'] ?? '', ['embed-image', 'attachment'], true) && preg_match('/^[A-Za-z0-9_-]+$/', (string) ($node['fileToken'] ?? ''))) {
                $id = hash_hmac('sha256', $store->id.'|'.$node['fileToken'], config('app.key'));
                $assets[$id] = ['id' => $id, 'token' => $node['fileToken'], 'kind' => $node['type'] === 'embed-image' ? 'image' : 'file', 'name' => trim((string) ($node['text'] ?? '')) ?: ($node['type'] === 'embed-image' ? '营业执照图片' : '营业执照附件')];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($values);

        return array_values($assets);
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(str_replace([' ', '　', "\n", "\r", '登录URL'], ['', '', '', '', '登陆URL'], trim($value)));
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);

        return in_array($parts['scheme'] ?? '', ['https', 'http'], true) && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }
}
