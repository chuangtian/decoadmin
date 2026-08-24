<?php

namespace App\Console\Commands;

use App\Services\NaturalTraffic\NaturalTrafficDataSyncService;
use Illuminate\Console\Command;

class SyncNaturalTrafficData extends Command
{
    protected $signature = 'natural-traffic:sync {channel? : brand-media、influencer-operations、edm-email 或 affiliate-marketing} {--store= : 仅同步指定店铺 ID}';

    protected $description = '将自然流量业务数据同步至当前项目 MySQL，页面不直接调用第三方接口';

    public function handle(NaturalTrafficDataSyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;
        $channel = $this->argument('channel');

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        if (filled($channel) && ! isset(NaturalTrafficDataSyncService::SECTIONS[$channel])) {
            $this->components->error('自然流量渠道无效。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredSources($storeId ?: null, filled($channel) ? (string) $channel : null);
        $this->components->info(sprintf(
            '自然流量同步完成：%d 个店铺、%d 个来源、%d 张表、%d 个字段、%d 条记录；失败 %d。',
            $result['stores'], $result['sources'], $result['tables'], $result['fields'], $result['records'], $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->components->warn(sprintf(
                '组织 %d / 店铺 %d / %s：%s',
                $failure['organization_id'], $failure['store_id'], $failure['channel'], $failure['error'],
            ));
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
