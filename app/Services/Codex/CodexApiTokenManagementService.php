<?php

namespace App\Services\Codex;

use App\Models\AuditLog;
use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CodexApiTokenManagementService
{
    /** @return list<array{slug: string, label: string, group: string, description: string}> */
    public function abilityCatalog(): array
    {
        return [
            ['slug' => 'stores:read', 'label' => '查看店铺', 'group' => 'read', 'description' => '列出用户有权访问的店铺。'],
            ['slug' => 'dashboard:read', 'label' => '查看经营数据', 'group' => 'read', 'description' => '查看销售额、订单数和趋势。'],
            ['slug' => 'orders:read', 'label' => '查看脱敏订单', 'group' => 'read', 'description' => '查看不含顾客联系方式的订单。'],
            ['slug' => 'operations:read', 'label' => '查看运行状态', 'group' => 'read', 'description' => '查看同步、Webhook 和运行摘要。'],
            ['slug' => 'configuration:read', 'label' => '检查配置', 'group' => 'read', 'description' => '只返回配置是否完整，不返回密钥。'],
            ['slug' => 'system:read', 'label' => '查看系统状态', 'group' => 'read', 'description' => '查看服务、队列和调度器健康状态。'],
            ['slug' => 'analytics:write', 'label' => '刷新经营数据', 'group' => 'write', 'description' => '二次确认后刷新经营分析缓存。'],
            ['slug' => 'sync:write', 'label' => '发起和重试同步', 'group' => 'write', 'description' => '二次确认后发起或重试 Shopify 数据同步。'],
            ['slug' => 'configuration:write', 'label' => '修改安全配置', 'group' => 'write', 'description' => '二次确认后修改通知开关或学生优惠状态。'],
        ];
    }

    /** @param list<string> $abilities
     * @return array{token: CodexApiToken, plain_text_token: ?string, replayed: bool}
     */
    public function issueForAdministrator(
        User $actor,
        User $recipient,
        Organization $organization,
        string $name,
        array $abilities,
        int $expiresInDays,
        string $idempotencyKey,
    ): array {
        if (! $actor->hasPermission('codex.tokens.manage', $organization)) {
            throw new AuthorizationException('当前用户无权管理 Codex 插件授权。');
        }

        return $this->issue($actor, $recipient, $organization, $name, $abilities, $expiresInDays, $idempotencyKey, 'web');
    }

    /** @param list<string> $abilities
     * @return array{token: CodexApiToken, plain_text_token: string, replayed: false}
     */
    public function issueFromConsole(
        User $recipient,
        Organization $organization,
        string $name,
        array $abilities,
        int $expiresInDays,
    ): array {
        /** @var array{token: CodexApiToken, plain_text_token: string, replayed: false} $result */
        $result = $this->issue($recipient, $recipient, $organization, $name, $abilities, $expiresInDays, null, 'console');

        return $result;
    }

    /** @return array{token: CodexApiToken, replayed: bool} */
    public function revokeForAdministrator(User $actor, Organization $organization, CodexApiToken $token): array
    {
        if (! $actor->hasPermission('codex.tokens.manage', $organization)) {
            throw new AuthorizationException('当前用户无权管理 Codex 插件授权。');
        }
        if ((int) $token->organization_id !== (int) $organization->getKey()) {
            abort(404);
        }

        return $this->revoke($actor, $organization, $token, 'web');
    }

    /** @return array{token: CodexApiToken, replayed: bool} */
    public function revokeFromConsole(CodexApiToken $token): array
    {
        return $this->revoke($token->user, $token->organization, $token, 'console');
    }

    /** @param list<string> $abilities
     * @return array{token: CodexApiToken, plain_text_token: ?string, replayed: bool}
     */
    private function issue(
        User $actor,
        User $recipient,
        Organization $organization,
        string $name,
        array $abilities,
        int $expiresInDays,
        ?string $idempotencyKey,
        string $source,
    ): array {
        if ($recipient->status !== 'active'
            || ! $recipient->hasVerifiedEmail()
            || ! $recipient->organizations()->whereKey($organization->getKey())->exists()) {
            throw ValidationException::withMessages(['user_id' => '只能为当前组织内已验证邮箱的启用成员授权。']);
        }

        $abilities = $this->canonicalAbilities($abilities);
        $idempotencyHash = $idempotencyKey ? hash('sha256', json_encode([
            'user_id' => $recipient->getKey(),
            'name' => $name,
            'abilities' => $abilities,
            'expires_in_days' => $expiresInDays,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null;

        try {
            return DB::transaction(function () use ($actor, $recipient, $organization, $name, $abilities, $expiresInDays, $idempotencyKey, $idempotencyHash, $source): array {
                if ($idempotencyKey) {
                    $existing = $this->idempotentToken($organization, $actor, $idempotencyKey, true);
                    if ($existing) {
                        return $this->replayedIssue($existing, (string) $idempotencyHash);
                    }
                }

                $issued = CodexApiToken::issue(
                    $recipient,
                    $organization,
                    $name,
                    $abilities,
                    $expiresInDays,
                    $actor,
                    $idempotencyKey,
                    $idempotencyHash,
                );
                AuditLog::query()->create([
                    'organization_id' => $organization->getKey(),
                    'user_id' => $actor->getKey(),
                    'action' => 'codex_api_token_issued',
                    'subject_type' => CodexApiToken::class,
                    'subject_id' => $issued['token']->getKey(),
                    'metadata' => [
                        'token_uuid' => $issued['token']->uuid,
                        'recipient_user_id' => $recipient->getKey(),
                        'name' => $issued['token']->name,
                        'abilities' => $issued['token']->abilities,
                        'expires_at' => $issued['token']->expires_at->toIso8601String(),
                        'idempotency_key' => $idempotencyKey,
                        'source' => $source,
                    ],
                ]);

                return ['token' => $issued['token'], 'plain_text_token' => $issued['plain_text_token'], 'replayed' => false];
            });
        } catch (QueryException $exception) {
            $existing = $idempotencyKey
                ? $this->idempotentToken($organization, $actor, $idempotencyKey)
                : null;
            if (! $existing) {
                throw $exception;
            }

            return $this->replayedIssue($existing, (string) $idempotencyHash);
        }
    }

    /** @return array{token: CodexApiToken, replayed: bool} */
    private function revoke(User $actor, Organization $organization, CodexApiToken $token, string $source): array
    {
        return DB::transaction(function () use ($actor, $organization, $token, $source): array {
            $locked = CodexApiToken::query()->whereKey($token->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                return ['token' => $locked, 'replayed' => true];
            }

            $locked->forceFill(['revoked_at' => now()])->save();
            AuditLog::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $actor->getKey(),
                'action' => 'codex_api_token_revoked',
                'subject_type' => CodexApiToken::class,
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'token_uuid' => $locked->uuid,
                    'recipient_user_id' => $locked->user_id,
                    'name' => $locked->name,
                    'source' => $source,
                ],
            ]);

            return ['token' => $locked, 'replayed' => false];
        });
    }

    /** @param list<string> $abilities
     * @return list<string>
     */
    private function canonicalAbilities(array $abilities): array
    {
        $unknown = array_diff($abilities, CodexApiToken::SUPPORTED_ABILITIES);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['abilities' => '包含不支持的插件能力。']);
        }

        return array_values(array_filter(
            CodexApiToken::SUPPORTED_ABILITIES,
            fn (string $ability): bool => in_array($ability, $abilities, true),
        ));
    }

    private function idempotentToken(
        Organization $organization,
        User $actor,
        string $idempotencyKey,
        bool $lock = false,
    ): ?CodexApiToken {
        return CodexApiToken::query()
            ->where('organization_id', $organization->getKey())
            ->where('issued_by', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** @return array{token: CodexApiToken, plain_text_token: null, replayed: true} */
    private function replayedIssue(CodexApiToken $token, string $idempotencyHash): array
    {
        if (! $token->idempotency_hash || ! hash_equals($token->idempotency_hash, $idempotencyHash)) {
            throw ValidationException::withMessages(['idempotency_key' => '同一幂等键不能用于不同授权参数。']);
        }

        return ['token' => $token, 'plain_text_token' => null, 'replayed' => true];
    }
}
