<?php

namespace App\Services\Feishu;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FeishuBitableClient
{
    private ?string $tenantAccessToken = null;

    /** @return list<array<string, mixed>> */
    public function fields(string $appToken, string $tableId): array
    {
        return $this->allPages(
            $this->tablePath($appToken, $tableId).'/fields',
            ['page_size' => 100],
        );
    }

    /** @return list<array<string, mixed>> */
    public function records(string $appToken, string $tableId, ?string $viewId = null): array
    {
        $query = ['page_size' => 500];

        if (filled($viewId)) {
            $query['view_id'] = trim((string) $viewId);
        }

        return $this->allPages($this->tablePath($appToken, $tableId).'/records', $query);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function allPages(string $path, array $query): array
    {
        $items = [];
        $pageToken = null;
        $maxPages = max(1, (int) config('services.feishu_table.max_pages', 200));

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageQuery = $query;

            if ($pageToken !== null) {
                $pageQuery['page_token'] = $pageToken;
            }

            $payload = $this->get($path, $pageQuery);
            $pageItems = data_get($payload, 'data.items', []);

            if (! is_array($pageItems)) {
                throw new RuntimeException('飞书返回了无效的数据列表。');
            }

            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (! (bool) data_get($payload, 'data.has_more', false)) {
                return $items;
            }

            $nextPageToken = data_get($payload, 'data.page_token');

            if (! is_string($nextPageToken) || $nextPageToken === '' || $nextPageToken === $pageToken) {
                throw new RuntimeException('飞书分页游标无效，已停止同步。');
            }

            $pageToken = $nextPageToken;
        }

        throw new RuntimeException('飞书数据超过同步分页上限，请缩小视图范围或提高分页上限。');
    }

    /** @param array<string, int|string> $query */
    private function get(string $path, array $query): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($this->accessToken())
            ->timeout($this->timeout())
            ->retry(3, 300, throw: false)
            ->get($path, $query);

        return $this->validatedPayload($response, '读取飞书多维表格');
    }

    private function accessToken(): string
    {
        if ($this->tenantAccessToken !== null) {
            return $this->tenantAccessToken;
        }

        $appId = trim((string) config('services.feishu_table.app_id', ''));
        $appSecret = trim((string) config('services.feishu_table.app_secret', ''));

        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException('飞书开放平台 App ID 或 App Secret 未配置。');
        }

        $response = Http::baseUrl($this->baseUrl())
            ->asJson()
            ->acceptJson()
            ->timeout($this->timeout())
            ->retry(3, 300, throw: false)
            ->post('/auth/v3/tenant_access_token/internal', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
            ]);
        $payload = $this->validatedPayload($response, '获取飞书访问凭证');
        $token = data_get($payload, 'tenant_access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('飞书访问凭证响应缺少 token。');
        }

        return $this->tenantAccessToken = $token;
    }

    private function validatedPayload(Response $response, string $operation): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("{$operation}失败（HTTP {$response->status()}）。");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("{$operation}失败（响应格式无效）。");
        }

        $code = (int) ($payload['code'] ?? -1);

        if ($code !== 0) {
            throw new RuntimeException("{$operation}失败（飞书错误码 {$code}）。");
        }

        return $payload;
    }

    private function tablePath(string $appToken, string $tableId): string
    {
        $appToken = trim($appToken);
        $tableId = trim($tableId);

        if ($appToken === '' || $tableId === '') {
            throw new RuntimeException('亚马逊多维表格 App Token 或 Table ID 未配置。');
        }

        return '/bitable/v1/apps/'.rawurlencode($appToken).'/tables/'.rawurlencode($tableId);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.feishu_table.base_url', 'https://open.feishu.cn/open-apis'), '/');
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.feishu_table.timeout', 20));
    }
}
