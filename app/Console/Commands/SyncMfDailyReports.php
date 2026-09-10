<?php

namespace App\Console\Commands;

use App\Services\Feishu\MfDailyReportSyncService;
use Illuminate\Console\Command;

class SyncMfDailyReports extends Command
{
    protected $signature = 'feishu:sync-mf-daily-reports {--store= : 仅同步指定店铺 ID}';

    protected $description = '只同步 MF数据表，并在发现新记录时生成一条飞书每日数据汇报';

    public function handle(MfDailyReportSyncService $sync): int
    {
        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;

        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncConfiguredStores($storeId ?: null);
        $this->components->info(sprintf(
            'MF数据表同步完成：店铺 %d，首次基线 %d，发现新记录 %d，待发送 %d，失败 %d。',
            $result['stores'],
            $result['baseline'],
            $result['inserted'],
            $result['queued'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->components->warn(sprintf('店铺 %d：%s', $failure['store_id'], $failure['error']));
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
