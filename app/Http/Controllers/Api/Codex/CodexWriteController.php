<?php

namespace App\Http\Controllers\Api\Codex;

use App\Http\Controllers\Controller;
use App\Models\CodexActionConfirmation;
use App\Models\Store;
use App\Models\SyncJob;
use App\Services\Codex\CodexActionConfirmationService;
use App\Services\Codex\CodexApiAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CodexWriteController extends Controller
{
    private const IDEMPOTENCY_RULES = ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'];

    public function __construct(
        private CodexApiAccessService $access,
        private CodexActionConfirmationService $confirmations,
    ) {}

    public function prepareAnalyticsRefresh(Request $request, Store $store): JsonResponse
    {
        [$user, $organization, $token] = $this->access->scopedStoreContext($request, $store, 'analytics:write');
        $values = $request->validate(['idempotency_key' => self::IDEMPOTENCY_RULES]);

        return $this->prepared($this->confirmations->prepare(
            $token,
            $user,
            $organization,
            $store,
            'refresh_analytics',
            [],
            "刷新店铺 #{$store->id} {$store->name} 的经营分析缓存。",
            $values['idempotency_key'],
        ));
    }

    public function prepareSync(Request $request, Store $store): JsonResponse
    {
        [$user, $organization, $token] = $this->access->scopedStoreContext($request, $store, 'sync:write');
        $values = $request->validate([
            'type' => ['required', Rule::in(['products', 'orders', 'customers', 'inventory'])],
            'mode' => ['required', Rule::in(['full', 'incremental'])],
            'app_installation_id' => ['nullable', 'integer'],
            'idempotency_key' => self::IDEMPOTENCY_RULES,
        ]);
        if (filled($values['app_installation_id'] ?? null)) {
            abort_unless($store->appInstallations()->where('status', 'active')->whereKey($values['app_installation_id'])->exists(), 404);
        }
        $payload = collect($values)->only(['type', 'mode', 'app_installation_id'])->filter(fn ($value) => $value !== null)->all();
        $typeLabel = ['products' => '商品', 'orders' => '订单', 'customers' => '客户', 'inventory' => '库存'][$values['type']];
        $modeLabel = $values['mode'] === 'full' ? '全量' : '增量';

        return $this->prepared($this->confirmations->prepare(
            $token,
            $user,
            $organization,
            $store,
            'start_sync',
            $payload,
            "为店铺 #{$store->id} {$store->name} 发起{$typeLabel}{$modeLabel}同步。",
            $values['idempotency_key'],
        ));
    }

    public function prepareSyncRetry(Request $request, Store $store): JsonResponse
    {
        [$user, $organization, $token] = $this->access->scopedStoreContext($request, $store, 'sync:write');
        $values = $request->validate([
            'sync_job_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => self::IDEMPOTENCY_RULES,
        ]);
        $job = SyncJob::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->findOrFail($values['sync_job_id']);

        return $this->prepared($this->confirmations->prepare(
            $token,
            $user,
            $organization,
            $store,
            'retry_sync',
            ['sync_job_id' => $job->getKey()],
            "重试店铺 #{$store->id} {$store->name} 的失败同步任务 #{$job->id}（{$job->type}）。",
            $values['idempotency_key'],
        ));
    }

    public function prepareStoreNotifications(Request $request, Store $store): JsonResponse
    {
        [$user, $organization, $token] = $this->access->scopedStoreContext($request, $store, 'configuration:write');
        $values = $request->validate([
            'mail_enabled' => ['sometimes', 'boolean'],
            'feishu_enabled' => ['sometimes', 'boolean'],
            'notify_sync_failed' => ['sometimes', 'boolean'],
            'notify_webhook_failed' => ['sometimes', 'boolean'],
            'notify_connection_unhealthy' => ['sometimes', 'boolean'],
            'idempotency_key' => self::IDEMPOTENCY_RULES,
        ]);
        $payload = collect([
            'mail_enabled', 'feishu_enabled', 'notify_sync_failed',
            'notify_webhook_failed', 'notify_connection_unhealthy',
        ])->filter(fn (string $key): bool => $request->exists($key))
            ->mapWithKeys(fn (string $key): array => [$key => (bool) $values[$key]])
            ->all();
        if ($payload === []) {
            throw ValidationException::withMessages(['settings' => '至少提供一个允许修改的通知开关。']);
        }
        $labels = [
            'mail_enabled' => '邮件通知',
            'feishu_enabled' => '飞书通知',
            'notify_sync_failed' => '同步失败提醒',
            'notify_webhook_failed' => 'Webhook 失败提醒',
            'notify_connection_unhealthy' => '连接异常提醒',
        ];
        $summary = collect($payload)
            ->map(fn (bool $enabled, string $key): string => $labels[$key].'='.($enabled ? '开启' : '关闭'))
            ->implode('，');

        return $this->prepared($this->confirmations->prepare(
            $token,
            $user,
            $organization,
            $store,
            'update_store_notifications',
            $payload,
            "修改店铺 #{$store->id} {$store->name} 的通知设置：{$summary}。",
            $values['idempotency_key'],
        ));
    }

    public function prepareStudentDiscountStatus(Request $request, Store $store): JsonResponse
    {
        [$user, $organization, $token] = $this->access->scopedStoreContext($request, $store, 'configuration:write');
        $values = $request->validate([
            'enabled' => ['required', 'boolean'],
            'idempotency_key' => self::IDEMPOTENCY_RULES,
        ]);
        $enabled = (bool) $values['enabled'];

        return $this->prepared($this->confirmations->prepare(
            $token,
            $user,
            $organization,
            $store,
            'set_student_discount_enabled',
            ['enabled' => $enabled],
            ($enabled ? '开启' : '关闭')."店铺 #{$store->id} {$store->name} 的学生优惠活动。",
            $values['idempotency_key'],
        ));
    }

    public function execute(Request $request, string $confirmation): JsonResponse
    {
        [$user, $organization, $token] = $this->access->authenticatedContext($request);
        $values = $request->validate([
            'confirmation_text' => ['required', 'string', Rule::in([CodexActionConfirmationService::CONFIRMATION_TEXT])],
        ]);
        $executed = $this->confirmations->execute(
            $token,
            $user,
            $organization,
            $confirmation,
            $values['confirmation_text'],
        );

        return $this->response([
            'confirmation' => $this->confirmation($executed['confirmation']),
            'result' => $executed['result'],
            'idempotent_replay' => $executed['replayed'],
        ]);
    }

    /** @param array{confirmation: CodexActionConfirmation, reused: bool} $prepared */
    private function prepared(array $prepared): JsonResponse
    {
        return $this->response([
            'confirmation' => $this->confirmation($prepared['confirmation']),
            'idempotent_replay' => $prepared['reused'],
            'required_confirmation_text' => CodexActionConfirmationService::CONFIRMATION_TEXT,
        ], 202);
    }

    /** @return array<string, mixed> */
    private function confirmation(CodexActionConfirmation $confirmation): array
    {
        return [
            'id' => $confirmation->uuid,
            'action' => $confirmation->action,
            'summary' => $confirmation->summary,
            'status' => $confirmation->status,
            'expires_at' => $confirmation->expires_at->toIso8601String(),
            'executed_at' => $confirmation->executed_at?->toIso8601String(),
        ];
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'schema_version' => 'decoadmin-codex-v1',
            'data' => $data,
            'meta' => ['generated_at' => now()->toIso8601String()],
        ], $status);
    }
}
