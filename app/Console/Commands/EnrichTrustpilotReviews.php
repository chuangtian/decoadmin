<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Reputation\TrustpilotReviewEnrichmentService;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class EnrichTrustpilotReviews extends Command
{
    protected $signature = 'reputation:enrich-trustpilot
        {--store= : 必填，目标店铺 ID}
        {--snapshot= : 必填，Trustpilot 公共评论 JSON 快照路径}
        {--dry-run : 只统计匹配结果，不保留数据库修改}';

    protected $description = '按评论正文唯一匹配 Trustpilot 评论人，并标识当前店铺在售车型';

    public function handle(TrustpilotReviewEnrichmentService $service): int
    {
        $storeId = (int) $this->option('store');
        $snapshotPath = trim((string) $this->option('snapshot'));
        if ($storeId <= 0 || $snapshotPath === '') {
            $this->error('必须同时提供 --store 和 --snapshot。');

            return self::FAILURE;
        }

        $store = Store::query()->whereKey($storeId)->where('status', 'active')->first();
        if (! $store) {
            $this->error('未找到指定的启用店铺。');

            return self::FAILURE;
        }
        if (! is_file($snapshotPath) || ! is_readable($snapshotPath)) {
            $this->error('Trustpilot 快照文件不存在或不可读。');

            return self::FAILURE;
        }

        try {
            $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
            $result = $service->enrich($store, is_array($snapshot) ? $snapshot : [], (bool) $this->option('dry-run'));
        } catch (JsonException) {
            $this->error('Trustpilot 快照不是有效 JSON。');

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['项目', '数量'], [
            ['快照评论', $result['snapshot_rows']],
            ['数据库评论', $result['mentions_processed']],
            ['姓名唯一匹配', $result['reviewer_matches']],
            ['姓名/链接更新', $result['reviewer_updates']],
            ['识别到车型的评论', $result['mentions_with_product_matches']],
            ['车型关联数', $result['product_matches']],
        ]);
        $this->info($result['dry_run'] ? '试运行完成，数据库未修改。' : 'Trustpilot 评论补全完成。');

        return self::SUCCESS;
    }
}
