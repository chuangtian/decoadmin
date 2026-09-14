<?php

namespace DecoReviews\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneWebhookReceipts extends Command
{
    protected $signature = 'deco-reviews:prune-webhook-receipts';

    protected $description = 'Remove expired Deco Reviews webhook idempotency receipts';

    public function handle(): int
    {
        $ids = DB::table('deco_review_webhook_receipts')->where('processed_at', '<', now()->subDays(90))
            ->orderBy('id')->limit(5000)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('deco_review_webhook_receipts')->whereIn('id', $ids)->delete();
        }

        return self::SUCCESS;
    }
}
