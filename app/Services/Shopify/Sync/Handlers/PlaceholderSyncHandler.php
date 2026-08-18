<?php

namespace App\Services\Shopify\Sync\Handlers;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Models\SyncJob;
use App\Services\Shopify\Sync\SyncResult;

abstract class PlaceholderSyncHandler implements SyncHandlerInterface
{
    abstract protected function label(): string;

    public function handle(SyncJob $syncJob): SyncResult
    {
        return SyncResult::successful(
            $this->label().'同步处理器基础结构执行完成，本阶段未读取 Shopify 数据。',
            metadata: [
                'framework_only' => true,
                'handler' => static::class,
                'sync_job_id' => $syncJob->getKey(),
            ],
        );
    }
}
