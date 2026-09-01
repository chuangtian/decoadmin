<?php

namespace App\Services\Codex;

use App\Http\Controllers\Api\Codex\CodexReadController;
use App\Http\Controllers\Api\Codex\CodexWriteController;
use App\Models\Store;
use App\Services\DashboardMetricsService;
use App\Services\Shopify\ShopifyDataQueryService;
use App\Services\StoreOperationsQueryService;
use App\Services\SystemStatusService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CodexMcpToolService
{
    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'decoadmin_list_stores',
                'description' => '列出当前登录用户在 DecoAdmin 中有权查看的店铺。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'search' => ['type' => 'string', 'maxLength' => 100],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25],
                ]],
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_get_dashboard',
                'description' => '查询一个店铺的经营概览、统计周期、趋势和运行摘要。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'decoadmin_list_stores 返回的店铺 ID'],
                    'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => 30],
                    'include_test' => ['type' => 'boolean', 'default' => false],
                    'include_cancelled' => ['type' => 'boolean', 'default' => true],
                    'comparison' => ['type' => 'string', 'enum' => ['none', 'previous', 'year', 'year_weekday'], 'default' => 'previous'],
                ]],
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_list_orders',
                'description' => '分页查询一个店铺的脱敏订单信息，不返回顾客邮箱、电话或地址。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1],
                    'search' => ['type' => 'string', 'maxLength' => 100],
                    'financial_status' => ['type' => 'string', 'maxLength' => 40],
                    'fulfillment_status' => ['type' => 'string', 'enum' => ['fulfilled', 'partial', 'restocked', 'unfulfilled']],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ]],
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_get_operations',
                'description' => '查询一个店铺的同步、Webhook 和近期运行状态。',
                'inputSchema' => $this->storeSchema(),
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_get_configuration_status',
                'description' => '检查系统及店铺配置是否完整，仅返回布尔状态和缺失字段，不返回配置值或密钥。',
                'inputSchema' => $this->storeSchema(),
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_get_system_status',
                'description' => '查询当前组织可见的 DecoAdmin 服务、队列、调度器和近期事故摘要。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) []],
                'annotations' => $read,
            ],
            [
                'name' => 'decoadmin_prepare_analytics_refresh',
                'description' => '生成刷新经营分析数据的确认单，不立即执行。必须向用户展示摘要并等待后续明确回复“确认执行”。',
                'inputSchema' => $this->storeWriteSchema(),
                'annotations' => $this->writeAnnotations(false),
            ],
            [
                'name' => 'decoadmin_prepare_sync',
                'description' => '生成发起 Shopify 数据同步的确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id', 'type', 'mode'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1],
                    'type' => ['type' => 'string', 'enum' => ['products', 'orders', 'customers', 'inventory']],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'incremental']],
                    'app_installation_id' => ['type' => 'integer', 'minimum' => 1],
                    'idempotency_key' => $this->idempotencySchema(),
                ]],
                'annotations' => $this->writeAnnotations(true),
            ],
            [
                'name' => 'decoadmin_prepare_sync_retry',
                'description' => '为一个失败同步任务生成重试确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id', 'sync_job_id'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1],
                    'sync_job_id' => ['type' => 'integer', 'minimum' => 1],
                    'idempotency_key' => $this->idempotencySchema(),
                ]],
                'annotations' => $this->writeAnnotations(true),
            ],
            [
                'name' => 'decoadmin_prepare_notification_update',
                'description' => '生成店铺通知开关修改确认单。只支持非密钥布尔设置，不能写入密码、Token 或 Webhook。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1],
                    'mail_enabled' => ['type' => 'boolean'],
                    'feishu_enabled' => ['type' => 'boolean'],
                    'notify_sync_failed' => ['type' => 'boolean'],
                    'notify_webhook_failed' => ['type' => 'boolean'],
                    'notify_connection_unhealthy' => ['type' => 'boolean'],
                    'idempotency_key' => $this->idempotencySchema(),
                ]],
                'annotations' => $this->writeAnnotations(false),
            ],
            [
                'name' => 'decoadmin_prepare_student_discount_status',
                'description' => '生成开启或关闭学生优惠活动的确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id', 'enabled'], 'properties' => [
                    'store_id' => ['type' => 'integer', 'minimum' => 1],
                    'enabled' => ['type' => 'boolean'],
                    'idempotency_key' => $this->idempotencySchema(),
                ]],
                'annotations' => $this->writeAnnotations(true),
            ],
            [
                'name' => 'decoadmin_execute_confirmed_action',
                'description' => '执行已生成的确认单。仅当用户在生成确认单后的新消息中明确回复“确认执行”时调用，绝不能在生成确认单的同一轮调用。',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['confirmation_id', 'confirmation_text'], 'properties' => [
                    'confirmation_id' => ['type' => 'string', 'format' => 'uuid'],
                    'confirmation_text' => ['type' => 'string', 'enum' => [CodexActionConfirmationService::CONFIRMATION_TEXT]],
                ]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true],
            ],
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(Request $request, string $name, array $arguments): array
    {
        try {
            $response = $this->invokeApi($request, $name, $arguments);
            $payload = $response->getData(true);
            if (! is_array($payload) || ($payload['schema_version'] ?? null) !== 'decoadmin-codex-v1') {
                throw new \RuntimeException('后台返回了无法识别的数据格式。');
            }

            return [
                'content' => [['type' => 'text', 'text' => '环境：'.$this->environmentLabel()."\n".$this->summary($name, $payload)]],
                'structuredContent' => [
                    'data' => $payload['data'] ?? null,
                    'meta' => [...($payload['meta'] ?? []), 'environment' => $this->environmentLabel()],
                ],
            ];
        } catch (Throwable $exception) {
            [$status, $message] = $this->safeError($exception);
            if ($status >= 500) {
                report($exception);
            }

            return [
                'content' => [['type' => 'text', 'text' => '环境：'.$this->environmentLabel()."\n".$message]],
                'isError' => true,
            ];
        }
    }

    /** @param array<string, mixed> $arguments */
    private function invokeApi(Request $source, string $name, array $arguments): JsonResponse
    {
        $request = clone $source;
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $request->query->replace([]);
        $request->request->replace($arguments);
        $read = app(CodexReadController::class);
        $write = app(CodexWriteController::class);

        return match ($name) {
            'decoadmin_list_stores' => $read->stores($request),
            'decoadmin_get_dashboard' => $read->dashboard($request, $this->store($arguments), app(DashboardMetricsService::class)),
            'decoadmin_list_orders' => $read->orders($request, $this->store($arguments), app(ShopifyDataQueryService::class)),
            'decoadmin_get_operations' => $read->operations($request, $this->store($arguments), app(StoreOperationsQueryService::class)),
            'decoadmin_get_configuration_status' => $read->configurationStatus($request, $this->store($arguments), app(CodexConfigurationStatusService::class)),
            'decoadmin_get_system_status' => $read->systemStatus($request, app(SystemStatusService::class)),
            'decoadmin_prepare_analytics_refresh' => $write->prepareAnalyticsRefresh($this->withIdempotency($request), $this->store($arguments)),
            'decoadmin_prepare_sync' => $write->prepareSync($this->withIdempotency($request), $this->store($arguments)),
            'decoadmin_prepare_sync_retry' => $write->prepareSyncRetry($this->withIdempotency($request), $this->store($arguments)),
            'decoadmin_prepare_notification_update' => $write->prepareStoreNotifications($this->withIdempotency($request), $this->store($arguments)),
            'decoadmin_prepare_student_discount_status' => $write->prepareStudentDiscountStatus($this->withIdempotency($request), $this->store($arguments)),
            'decoadmin_execute_confirmed_action' => $write->execute($request, (string) ($arguments['confirmation_id'] ?? '')),
            default => throw ValidationException::withMessages(['tool' => '不支持的 DecoAdmin 工具。']),
        };
    }

    private function withIdempotency(Request $request): Request
    {
        if (! $request->has('idempotency_key')) {
            $request->request->set('idempotency_key', (string) Str::uuid());
        }

        return $request;
    }

    /** @param array<string, mixed> $arguments */
    private function store(array $arguments): Store
    {
        $storeId = $arguments['store_id'] ?? null;
        if (! is_int($storeId) && ! (is_string($storeId) && ctype_digit($storeId))) {
            throw ValidationException::withMessages(['store_id' => 'store_id 必须是大于 0 的整数。']);
        }

        return Store::query()->findOrFail((int) $storeId);
    }

    /** @return array{0: int, 1: string} */
    private function safeError(Throwable $exception): array
    {
        if ($exception instanceof HttpResponseException) {
            $status = $exception->getResponse()->getStatusCode();
            $payload = json_decode((string) $exception->getResponse()->getContent(), true);

            return [$status, $this->statusMessage($status, data_get($payload, 'error.message'))];
        }
        if ($exception instanceof ValidationException) {
            return [422, collect($exception->errors())->flatten()->first() ?: '输入参数无效。'];
        }
        if ($exception instanceof ModelNotFoundException) {
            return [404, '目标资源不存在或当前用户无权访问。'];
        }
        if ($exception instanceof HttpExceptionInterface) {
            return [$exception->getStatusCode(), $this->statusMessage($exception->getStatusCode())];
        }

        return [500, '后台暂时不可用，请稍后重试。'];
    }

    private function statusMessage(int $status, ?string $fallback = null): string
    {
        return match ($status) {
            401 => '插件授权已失效，请重新连接。',
            403 => '当前用户权限不足。',
            404 => '目标资源不存在或当前用户无权访问。',
            409 => $fallback ?: '操作状态冲突，请刷新后重试。',
            410 => $fallback ?: '确认单已过期，请重新生成。',
            422 => $fallback ?: '输入参数无效。',
            429 => '请求过于频繁，请稍后重试。',
            default => $status >= 500 ? '后台暂时不可用，请稍后重试。' : ($fallback ?: '请求失败。'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function summary(string $name, array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return match ($name) {
            'decoadmin_list_stores' => $this->summarizeStores($data),
            'decoadmin_get_dashboard' => $this->summarizeDashboard($data),
            'decoadmin_list_orders' => $this->summarizeOrders($data),
            'decoadmin_get_operations' => $this->summarizeOperations($data),
            'decoadmin_get_configuration_status' => $this->summarizeConfiguration($data),
            'decoadmin_get_system_status' => $this->summarizeSystem($data),
            'decoadmin_execute_confirmed_action' => $this->summarizeExecuted($data),
            default => $this->summarizePrepared($data),
        };
    }

    /** @param array<string, mixed> $data */
    private function summarizeStores(array $data): string
    {
        $stores = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($stores === []) {
            return '当前账号没有可查看的活跃店铺。';
        }
        $lines = collect($stores)->map(fn (array $store): string => sprintf(
            '- #%s %s：Shopify %s，最近同步 %s',
            $store['id'] ?? '-',
            $store['name'] ?? '未命名店铺',
            $store['connection_status'] ?? '未知',
            data_get($store, 'last_sync.status', '暂无'),
        ))->implode("\n");

        return '共 '.data_get($data, 'pagination.total', count($stores))." 家店铺：\n".$lines;
    }

    /** @param array<string, mixed> $data */
    private function summarizeDashboard(array $data): string
    {
        $summary = data_get($data, 'analytics.summary', $data['summary'] ?? []);
        $currency = (string) data_get($data, 'store.currency', '');

        return implode("\n", [
            (string) data_get($data, 'store.name', '当前店铺').'经营概览：',
            '- 统计周期：'.data_get($data, 'analytics.period.from', '-').' 至 '.data_get($data, 'analytics.period.to', '-'),
            '- 净销售额：'.$this->currency(data_get($summary, 'net_sales', data_get($summary, 'sales')), $currency),
            '- 订单数：'.number_format((float) data_get($summary, 'orders', 0)),
            '- 平均订单金额：'.$this->currency(data_get($summary, 'average_order_value'), $currency),
            '- 开放告警：'.data_get($data, 'operations.open_alerts', 0).'；24 小时失败同步：'.data_get($data, 'operations.failed_sync_jobs_24h', 0).'；失败 Webhook：'.data_get($data, 'operations.failed_webhooks_24h', 0),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function summarizeOrders(array $data): string
    {
        $items = array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 10);
        $lines = collect($items)->map(fn (array $order): string => sprintf(
            '- %s：%s，支付 %s，履约 %s',
            $order['order_number'] ?? '#'.($order['id'] ?? '-'),
            $this->currency($order['total_price'] ?? 0, (string) ($order['currency'] ?? '')),
            $order['financial_status'] ?? '未知',
            $order['fulfillment_status'] ?? '未履约',
        ));

        return collect([
            '共 '.data_get($data, 'pagination.total', 0).' 笔订单，当前第 '.data_get($data, 'pagination.page', 1).'/'.data_get($data, 'pagination.last_page', 1).' 页。',
            ...$lines->all(),
        ])->implode("\n");
    }

    /** @param array<string, mixed> $data */
    private function summarizeOperations(array $data): string
    {
        return implode("\n", [
            '店铺运行摘要：',
            '- 同步任务：总计 '.data_get($data, 'sync.summary.total', 0).'，运行中 '.data_get($data, 'sync.summary.running', 0).'，失败 '.data_get($data, 'sync.summary.failed', 0),
            '- Webhook：总计 '.data_get($data, 'webhooks.summary.total', 0).'，处理中 '.data_get($data, 'webhooks.summary.active', 0).'，失败 '.data_get($data, 'webhooks.summary.failed', 0),
            '- 近期记录：操作 '.data_get($data, 'logs.summary.operations', 0).'，集成 '.data_get($data, 'logs.summary.integrations', 0).'，异常 '.data_get($data, 'logs.summary.exceptions', 0),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function summarizeConfiguration(array $data): string
    {
        $missing = [];
        foreach (['系统' => $data['system'] ?? [], '店铺通知' => $data['store_notifications'] ?? []] as $scope => $sections) {
            foreach ($sections as $section => $status) {
                if (! (bool) data_get($status, 'configured', false)) {
                    $missing[] = $scope.'.'.$section.'：'.(implode('、', data_get($status, 'missing', [])) ?: '未完成');
                }
            }
        }
        if (! (bool) data_get($data, 'shopify.connected', false)) {
            $missing[] = 'Shopify 连接：'.data_get($data, 'shopify.status', 'disconnected');
        }
        if (! (bool) data_get($data, 'student_discount.configured', false)) {
            $missing[] = '学生优惠：尚未配置';
        }

        return $missing === [] ? '已检查的系统与店铺配置均完整。' : '发现 '.count($missing)." 项未完成：\n- ".implode("\n- ", $missing);
    }

    /** @param array<string, mixed> $data */
    private function summarizeSystem(array $data): string
    {
        $services = collect($data['services'] ?? [])->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'healthy');
        $queues = collect($data['queues'] ?? [])->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'healthy');

        return implode("\n", [
            '系统整体状态：'.data_get($data, 'summary.status', 'unknown').'（'.data_get($data, 'summary.checked_at', '未知时间').'）',
            '- 服务异常/待确认：'.($services->isEmpty() ? '无' : $services->map(fn (array $item): string => ($item['name'] ?? 'service').':'.($item['status'] ?? 'unknown'))->implode('，')),
            '- 队列异常/待确认：'.($queues->isEmpty() ? '无' : $queues->map(fn (array $item): string => ($item['label'] ?? 'queue').':'.($item['status'] ?? 'unknown'))->implode('，')),
            '- 近 24 小时事故：'.(collect($data['incidents'] ?? [])->map(fn (array $item): string => ($item['label'] ?? '事故').' '.($item['count'] ?? '未知'))->implode('；') ?: '无'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function summarizePrepared(array $data): string
    {
        $confirmation = is_array($data['confirmation'] ?? null) ? $data['confirmation'] : [];
        if (($confirmation['status'] ?? null) === 'executed') {
            return '该操作已经执行。确认单：'.($confirmation['id'] ?? '未知');
        }

        return implode("\n", [
            '待确认操作：'.($confirmation['summary'] ?? '后台写操作'),
            '确认单：'.($confirmation['id'] ?? '未知'),
            '有效期至：'.($confirmation['expires_at'] ?? '未知'),
            '请向用户展示以上内容并停止。本轮不得执行；只有用户在后续消息中明确回复“确认执行”后才能继续。',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function summarizeExecuted(array $data): string
    {
        $confirmation = is_array($data['confirmation'] ?? null) ? $data['confirmation'] : [];

        return implode("\n", [
            ($data['idempotent_replay'] ?? false) ? '该确认单此前已经执行，本次返回原结果，没有重复写入。' : '已执行确认单。',
            '操作：'.($confirmation['summary'] ?? $confirmation['action'] ?? '后台写操作'),
            '确认单：'.($confirmation['id'] ?? '未知'),
            '执行时间：'.($confirmation['executed_at'] ?? '未知'),
        ]);
    }

    private function currency(mixed $value, string $code = ''): string
    {
        $amount = is_numeric($value) ? (float) $value : 0.0;

        return ($code !== '' ? $code.' ' : '').number_format($amount, 2, '.', ',');
    }

    /** @return array<string, mixed> */
    private function storeSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id'], 'properties' => [
            'store_id' => ['type' => 'integer', 'minimum' => 1],
        ]];
    }

    /** @return array<string, mixed> */
    private function storeWriteSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_id'], 'properties' => [
            'store_id' => ['type' => 'integer', 'minimum' => 1],
            'idempotency_key' => $this->idempotencySchema(),
        ]];
    }

    /** @return array<string, mixed> */
    private function idempotencySchema(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 120];
    }

    /** @return array<string, bool> */
    private function writeAnnotations(bool $openWorld): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => $openWorld];
    }

    private function environmentLabel(): string
    {
        return match (parse_url((string) config('app.url'), PHP_URL_HOST)) {
            'admin.decomkt.com' => '正式服',
            'testadmin.decomkt.com' => '测试服',
            'localhost', '127.0.0.1' => '本地开发',
            default => app()->environment(),
        };
    }
}
