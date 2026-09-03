<?php

namespace App\Services\Codex;

use App\Models\AuditLog;
use App\Models\CodexActionConfirmation;
use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CodexActionConfirmationService
{
    public const CONFIRMATION_TEXT = '确认执行';

    private const TTL_MINUTES = 10;

    public function __construct(private CodexWriteActionService $writer) {}

    /** @param array<string, mixed> $payload
     * @return array{confirmation: CodexActionConfirmation, reused: bool}
     */
    public function prepare(
        CodexApiToken $token,
        User $user,
        Organization $organization,
        Store $store,
        string $action,
        array $payload,
        string $summary,
        string $idempotencyKey,
    ): array {
        $this->authorize($token, $user, $organization, $store, $action, $payload);
        $payload = $this->canonicalize($payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($token, $user, $organization, $store, $action, $payload, $summary, $idempotencyKey, $payloadHash): array {
            $existing = CodexActionConfirmation::query()
                ->where('codex_api_token_id', $token->getKey())
                ->where('action', $action)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    $this->fail(409, 'codex_idempotency_conflict', '同一幂等键不能用于不同参数。');
                }
                if ($existing->status === 'pending' && $existing->isExpired()) {
                    $this->fail(409, 'codex_confirmation_expired', '原确认单已过期，请使用新的幂等键重新生成。');
                }

                return ['confirmation' => $existing, 'reused' => true];
            }

            $confirmation = CodexActionConfirmation::query()->create([
                'uuid' => (string) Str::uuid(),
                'codex_api_token_id' => $token->getKey(),
                'user_id' => $user->getKey(),
                'organization_id' => $organization->getKey(),
                'store_id' => $store->getKey(),
                'action' => $action,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'summary' => $summary,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
            AuditLog::query()->create([
                'organization_id' => $organization->getKey(),
                'store_id' => $store->getKey(),
                'user_id' => $user->getKey(),
                'action' => 'codex_action_confirmation_prepared',
                'subject_type' => CodexActionConfirmation::class,
                'subject_id' => $confirmation->getKey(),
                'metadata' => [
                    'confirmation_uuid' => $confirmation->uuid,
                    'action' => $action,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => $confirmation->expires_at->toIso8601String(),
                ],
            ]);

            return ['confirmation' => $confirmation, 'reused' => false];
        });
    }

    /** @return array{confirmation: CodexActionConfirmation, result: array<string, mixed>, replayed: bool} */
    public function execute(
        CodexApiToken $token,
        User $user,
        Organization $organization,
        string $confirmationUuid,
        string $confirmationText,
    ): array {
        if (! hash_equals(self::CONFIRMATION_TEXT, $confirmationText)) {
            $this->fail(422, 'codex_confirmation_required', '必须明确回复“确认执行”后才能执行写操作。');
        }

        return DB::transaction(function () use ($token, $user, $organization, $confirmationUuid): array {
            $confirmation = CodexActionConfirmation::query()
                ->with('store')
                ->where('uuid', $confirmationUuid)
                ->where('codex_api_token_id', $token->getKey())
                ->where('user_id', $user->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();
            if (! $confirmation) {
                $this->fail(404, 'codex_confirmation_not_found', '找不到当前用户的确认单。');
            }
            if ($confirmation->status === 'executed') {
                return [
                    'confirmation' => $confirmation,
                    'result' => $confirmation->result ?? [],
                    'replayed' => true,
                ];
            }
            if ($confirmation->status !== 'pending') {
                $this->fail(409, 'codex_confirmation_unavailable', '确认单当前不可执行。');
            }
            if ($confirmation->isExpired()) {
                $this->fail(410, 'codex_confirmation_expired', '确认单已过期，请重新生成。');
            }
            if (! $confirmation->store) {
                $this->fail(404, 'codex_store_not_found', '确认单对应店铺不存在。');
            }

            $payload = $confirmation->payload ?? [];
            $this->authorize(
                $token,
                $user,
                $organization,
                $confirmation->store,
                $confirmation->action,
                $payload,
            );
            $result = $this->writer->execute(
                $confirmation->action,
                $user,
                $organization,
                $confirmation->store,
                $payload,
            );
            $confirmation->forceFill([
                'status' => 'executed',
                'result' => $result,
                'executed_at' => now(),
            ])->save();
            AuditLog::query()->create([
                'organization_id' => $organization->getKey(),
                'store_id' => $confirmation->store_id,
                'user_id' => $user->getKey(),
                'action' => 'codex_action_executed',
                'subject_type' => CodexActionConfirmation::class,
                'subject_id' => $confirmation->getKey(),
                'metadata' => [
                    'confirmation_uuid' => $confirmation->uuid,
                    'action' => $confirmation->action,
                    'idempotency_key' => $confirmation->idempotency_key,
                ],
            ]);

            return ['confirmation' => $confirmation, 'result' => $result, 'replayed' => false];
        });
    }

    /** @param array<string, mixed> $payload */
    private function authorize(
        CodexApiToken $token,
        User $user,
        Organization $organization,
        Store $store,
        string $action,
        array $payload,
    ): void {
        if ((int) $token->user_id !== (int) $user->getKey()
            || (int) $token->organization_id !== (int) $organization->getKey()
            || (int) $store->organization_id !== (int) $organization->getKey()
            || ! $user->canAccessStore($store)) {
            $this->fail(403, 'codex_scope_forbidden', '当前用户无权访问该组织或店铺。');
        }

        [$ability, $permission] = match ($action) {
            'refresh_analytics' => ['analytics:write', 'reports.refresh'],
            'start_sync' => ['sync:write', 'sync.run'],
            'retry_sync' => ['sync:write', 'sync.retry'],
            'update_store_notifications' => ['configuration:write', 'store.update'],
            'set_student_discount_enabled' => ['configuration:write', 'student_discount.campaign.manage'],
            default => $this->fail(422, 'codex_action_unsupported', '不支持的 Codex 写操作。'),
        };
        if (! $token->allows($ability)) {
            $this->fail(403, 'codex_token_ability_forbidden', "当前令牌缺少 {$ability} 能力。");
        }
        if (! $user->hasPermission($permission, $organization, $store)) {
            $this->fail(403, 'codex_rbac_forbidden', "当前用户缺少 {$permission} 权限。");
        }

        if ($action === 'start_sync') {
            $typePermission = match ($payload['type'] ?? null) {
                'products' => 'products.sync',
                'orders' => 'orders.sync',
                'customers' => 'customers.sync',
                'inventory' => 'inventory.sync',
                default => $this->fail(422, 'codex_sync_type_invalid', '不支持的同步类型。'),
            };
            if (! $user->hasPermission($typePermission, $organization, $store)) {
                $this->fail(403, 'codex_rbac_forbidden', "当前用户缺少 {$typePermission} 权限。");
            }
        }

        if ($action === 'retry_sync') {
            $job = SyncJob::query()
                ->where('organization_id', $organization->getKey())
                ->where('store_id', $store->getKey())
                ->find((int) ($payload['sync_job_id'] ?? 0));
            if (! $job) {
                $this->fail(404, 'codex_sync_job_not_found', '找不到当前店铺的同步任务。');
            }
            if ($job->status !== 'failed') {
                $this->fail(422, 'codex_sync_job_not_failed', '只有失败的同步任务可以重试。');
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status));
    }
}
