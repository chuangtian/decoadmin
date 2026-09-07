<?php

namespace App\Console\Commands;

use App\Models\CodexApiToken;
use App\Services\Codex\CodexApiTokenManagementService;
use Illuminate\Console\Command;

class RevokeCodexApiToken extends Command
{
    protected $signature = 'codex:revoke-token {token : 令牌 UUID}';

    protected $description = '撤销一个 DecoAdmin Codex 插件访问令牌';

    public function handle(CodexApiTokenManagementService $tokens): int
    {
        $token = CodexApiToken::query()->with('user')->where('uuid', (string) $this->argument('token'))->first();
        if (! $token) {
            $this->error('找不到指定令牌。');

            return self::FAILURE;
        }

        $result = $tokens->revokeFromConsole($token);
        if ($result['replayed']) {
            $this->info('该令牌已经撤销，无需重复操作。');

            return self::SUCCESS;
        }

        $this->info('Codex 插件访问令牌已撤销。');

        return self::SUCCESS;
    }
}
