<?php

namespace App\Console\Commands;

use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Codex\CodexApiTokenManagementService;
use Illuminate\Console\Command;

class IssueCodexApiToken extends Command
{
    protected $signature = 'codex:issue-token
        {user : 用户 ID 或邮箱}
        {organization : 组织 ID 或 code}
        {--name=Codex private plugin : 令牌名称}
        {--abilities= : 逗号分隔的能力，留空使用全部只读能力}
        {--expires=30 : 有效天数，1-365}
        {--plain : 仅向标准输出写入令牌，供本机安全配置脚本读取}';

    protected $description = '签发一个只显示一次的 DecoAdmin Codex 私有插件访问令牌';

    public function handle(CodexApiTokenManagementService $tokens): int
    {
        $userValue = (string) $this->argument('user');
        $organizationValue = (string) $this->argument('organization');
        $user = User::query()
            ->where('email', $userValue)
            ->when(ctype_digit($userValue), fn ($query) => $query->orWhere('id', (int) $userValue))
            ->first();
        $organization = Organization::query()
            ->where('code', $organizationValue)
            ->when(ctype_digit($organizationValue), fn ($query) => $query->orWhere('id', (int) $organizationValue))
            ->first();

        if (! $user || ! $organization) {
            $this->error('找不到指定用户或组织。');

            return self::FAILURE;
        }

        if (! ($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists())) {
            $this->error('该用户不属于指定组织。');

            return self::FAILURE;
        }

        $expires = (int) $this->option('expires');
        if ($expires < 1 || $expires > 365) {
            $this->error('有效天数必须在 1 到 365 之间。');

            return self::FAILURE;
        }

        $abilities = filled($this->option('abilities'))
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('abilities')))))
            : CodexApiToken::DEFAULT_ABILITIES;
        $unknown = array_diff($abilities, CodexApiToken::SUPPORTED_ABILITIES);
        if ($unknown !== []) {
            $this->error('包含不支持的能力：'.implode(', ', $unknown));

            return self::FAILURE;
        }

        $issued = $tokens->issueFromConsole(
            $user,
            $organization,
            trim((string) $this->option('name')) ?: 'Codex private plugin',
            $abilities,
            $expires,
        );

        if ($this->option('plain')) {
            $this->line($issued['plain_text_token']);

            return self::SUCCESS;
        }

        $this->warn('请立即安全保存以下令牌；系统不会再次显示明文。');
        $this->line($issued['plain_text_token']);
        $this->newLine();
        $this->info('令牌 UUID：'.$issued['token']->uuid);
        $this->info('到期时间：'.$issued['token']->expires_at->toIso8601String());

        return self::SUCCESS;
    }
}
