<?php

namespace App\Services\Feishu;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FeishuBitableClient
{
    private ?string $tenantAccessToken = null;

    /** @return list<array<string, mixed>> */
    public function tables(string $appToken): array
    {
        $appToken = $this->requiredToken($appToken, '飞书多维表格');

        return $this->allPages(
            '/bitable/v1/apps/'.rawurlencode($appToken).'/tables',
            ['page_size' => 100],
            '读取飞书多维表格数据表',
        );
    }

    /** @return list<array<string, mixed>> */
    public function spreadsheetSheets(string $spreadsheetToken): array
    {
        $spreadsheetToken = $this->requiredToken($spreadsheetToken, '飞书电子表格');
        $payload = $this->get(
            '/sheets/v3/spreadsheets/'.rawurlencode($spreadsheetToken).'/sheets/query',
            [],
            '读取飞书电子表格工作表',
        );
        $sheets = data_get($payload, 'data.sheets');

        if (! is_array($sheets)) {
            throw new RuntimeException('飞书电子表格工作表响应无效。');
        }

        return array_values(array_filter($sheets, is_array(...)));
    }

    /** @return list<array<int, mixed>> */
    public function spreadsheetValues(
        string $spreadsheetToken,
        string $sheetId,
        int $rowCount,
        int $columnCount,
    ): array {
        $spreadsheetToken = $this->requiredToken($spreadsheetToken, '飞书电子表格');
        $sheetId = $this->requiredToken($sheetId, '飞书电子表格工作表');
        $rowCount = max(1, $rowCount);
        $columnCount = max(1, $columnCount);
        $range = $sheetId.'!A1:'.$this->columnLetters($columnCount).$rowCount;
        $payload = $this->get(
            '/sheets/v2/spreadsheets/'.rawurlencode($spreadsheetToken).'/values/'.rawurlencode($range),
            [],
            '读取飞书电子表格数据',
        );
        $values = data_get($payload, 'data.valueRange.values');

        if (! is_array($values)) {
            throw new RuntimeException('飞书电子表格数据响应无效。');
        }

        return array_values(array_filter($values, is_array(...)));
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $appToken, string $tableId): array
    {
        return $this->allPages(
            $this->tablePath($appToken, $tableId).'/fields',
            ['page_size' => 100],
            '读取飞书多维表格字段',
        );
    }

    /** @return list<array<string, mixed>> */
    public function records(
        string $appToken,
        string $tableId,
        ?string $viewId = null,
        bool $textFieldAsArray = false,
    ): array {
        $query = ['page_size' => 500];

        if (filled($viewId)) {
            $query['view_id'] = trim((string) $viewId);
        }

        if ($textFieldAsArray) {
            $query['text_field_as_array'] = 'true';
        }

        return $this->allPages(
            $this->tablePath($appToken, $tableId).'/records',
            $query,
            '读取飞书多维表格记录',
        );
    }

    /** @return array<string, mixed> */
    public function document(string $documentToken): array
    {
        $documentToken = $this->requiredToken($documentToken, '飞书文档');
        $payload = $this->get(
            '/docx/v1/documents/'.rawurlencode($documentToken),
            [],
            '读取飞书策划书信息',
        );
        $document = data_get($payload, 'data.document');

        if (! is_array($document)) {
            throw new RuntimeException('飞书策划书信息响应无效。');
        }

        return $document;
    }

    /** @return list<array<string, mixed>> */
    public function documentBlocks(string $documentToken): array
    {
        $documentToken = $this->requiredToken($documentToken, '飞书文档');

        return $this->allPages(
            '/docx/v1/documents/'.rawurlencode($documentToken).'/blocks',
            ['page_size' => 500, 'document_revision_id' => -1],
            '读取飞书策划书内容',
        );
    }

    /** @return array<string, mixed> */
    public function wikiNode(string $nodeToken): array
    {
        $nodeToken = $this->requiredToken($nodeToken, '飞书知识库节点');
        $payload = $this->get(
            '/wiki/v2/spaces/get_node',
            ['token' => $nodeToken],
            '解析飞书知识库节点',
        );
        $node = data_get($payload, 'data.node');

        if (! is_array($node)) {
            throw new RuntimeException('飞书知识库节点响应无效。');
        }

        return $node;
    }

    /** @return array{contents: string, content_type: string|null} */
    public function downloadMedia(string $fileToken): array
    {
        $fileToken = trim($fileToken);

        if ($fileToken === '') {
            throw new RuntimeException('飞书附件缺少文件 Token。');
        }

        $response = Http::baseUrl($this->baseUrl())
            ->accept('*/*')
            ->withToken($this->accessToken())
            ->timeout(max($this->timeout(), 60))
            ->retry(3, 300, throw: false)
            ->get('/drive/v1/medias/'.rawurlencode($fileToken).'/download');

        if (! $response->successful()) {
            throw new RuntimeException("下载飞书附件失败（HTTP {$response->status()}）。");
        }

        $contentType = $response->header('Content-Type');

        return [
            'contents' => $response->body(),
            'content_type' => is_string($contentType) && $contentType !== ''
                ? strtolower(trim(strtok($contentType, ';') ?: $contentType))
                : null,
        ];
    }

    /**
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function allPages(string $path, array $query, string $operation): array
    {
        $items = [];
        $pageToken = null;
        $maxPages = max(1, (int) config('services.feishu_table.max_pages', 200));

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageQuery = $query;

            if ($pageToken !== null) {
                $pageQuery['page_token'] = $pageToken;
            }

            $payload = $this->get($path, $pageQuery, $operation);
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
    private function get(string $path, array $query, string $operation): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($this->accessToken())
            ->timeout($this->timeout())
            ->retry(3, 300, throw: false)
            ->get($path, $query);

        return $this->validatedPayload($response, $operation);
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
            throw new RuntimeException('飞书多维表格 App Token 或 Table ID 未配置。');
        }

        return '/bitable/v1/apps/'.rawurlencode($appToken).'/tables/'.rawurlencode($tableId);
    }

    private function requiredToken(string $token, string $label): string
    {
        $token = trim($token);

        if ($token === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new RuntimeException("{$label} Token 无效。");
        }

        return $token;
    }

    private function columnLetters(int $columnNumber): string
    {
        $letters = '';

        while ($columnNumber > 0) {
            $columnNumber--;
            $letters = chr(65 + ($columnNumber % 26)).$letters;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $letters;
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
