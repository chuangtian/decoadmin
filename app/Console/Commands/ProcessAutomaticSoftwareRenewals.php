<?php

namespace App\Console\Commands;

use App\Services\FinanceService;
use Illuminate\Console\Command;

class ProcessAutomaticSoftwareRenewals extends Command
{
    protected $signature = 'finance:process-auto-renewals';

    protected $description = '将已到期的自动续费软件记录为已付款并顺延续费日期';

    public function handle(FinanceService $finance): int
    {
        $count = $finance->processAutomaticRenewals();
        $this->components->info("自动续费处理完成：{$count} 个项目。");

        return self::SUCCESS;
    }
}
