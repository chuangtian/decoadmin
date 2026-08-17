<?php

namespace App\Contracts\Shopify;

use App\Models\SyncJob;
use App\Services\Shopify\Sync\SyncResult;

interface SyncHandlerInterface
{
    public function type(): string;

    public function handle(SyncJob $syncJob): SyncResult;
}
