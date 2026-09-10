<?php

namespace DecoMarketing\Commands;

use App\Models\Store;
use DecoMarketing\Services\Engine;
use DecoMarketing\Services\Guard;
use Illuminate\Console\Command;

class RunMarketing extends Command
{
    protected $signature = 'marketing:run {--store= : Local store ID; only macfox-test-app is allowed}';

    protected $description = 'Process due marketing tasks for the authorized test store only';

    public function handle(): int
    {
        $stores = Store::where('shopify_domain', Guard::SHOP)->where('status', 'active')->when($this->option('store'), fn ($q) => $q->whereKey($this->option('store')))->limit(2)->get();
        if ($stores->count() !== 1) {
            $this->error('必须唯一定位 macfox-test-app 测试店铺。');

            return self::FAILURE;
        }
        $store = $stores->sole();
        app(Guard::class)->store($store);
        $result = app(Engine::class)->run($store);
        $this->info('Processed '.$result['processed']);

        return self::SUCCESS;
    }
}
