<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\MfDailyReportDelivery;
use App\Services\Feishu\MfDailyReportFormatter;
use App\Services\Feishu\MfDailyReportImageRenderer;
use App\Services\Feishu\MfDailyReportPublisher;
use App\Services\Feishu\MfDailyReportSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class DeliverMfDailyReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public int $deliveryId) {}

    public function handle(
        MfDailyReportFormatter $formatter,
        MfDailyReportImageRenderer $renderer,
        MfDailyReportPublisher $publisher,
    ): void {
        $delivery = MfDailyReportDelivery::query()->with('store')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $delivery->update([
            'status' => 'sending',
            'attempts' => $delivery->attempts + 1,
            'last_error' => null,
        ]);

        $table = FeishuBitableTable::query()
            ->where('organization_id', $delivery->organization_id)
            ->where('store_id', $delivery->store_id)
            ->where('source_section', MfDailyReportSyncService::SOURCE_SECTION)
            ->where('source_table_id', $delivery->source_table_id)
            ->first();
        if (! $table) {
            throw new RuntimeException('每日汇报对应的 MF数据表归档不存在。');
        }

        $record = $table->records()
            ->where('organization_id', $delivery->organization_id)
            ->where('store_id', $delivery->store_id)
            ->where('source_record_id', $delivery->source_record_id)
            ->first();
        if (! $record) {
            throw new RuntimeException('每日汇报对应的 MF数据记录不存在。');
        }

        $fields = $record->fields_encrypted ?? [];
        $missingFields = $formatter->missingFields($fields);
        if ($missingFields !== []) {
            throw new RuntimeException('MF数据记录缺少字段：'.implode('、', $missingFields).'。');
        }

        $report = $formatter->report($delivery->store, $fields);
        if ($report === null) {
            throw new RuntimeException('MF数据记录缺少有效日期。');
        }

        $history = $table->records()
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(function (FeishuBitableRecord $historyRecord) use ($formatter, $delivery): ?array {
                $fields = $historyRecord->fields_encrypted;

                return is_array($fields) ? $formatter->report($delivery->store, $fields) : null;
            })
            ->filter()
            ->sortBy('date')
            ->values()
            ->take(-30)
            ->all();
        $text = $formatter->text($report);
        $image = $renderer->render($report, $history);
        $delivery->update(['message_hash' => hash('sha256', $text)]);

        $publisher->publish($delivery->store, $text, $image);

        $delivery->update([
            'status' => 'sent',
            'last_error' => null,
            'sent_at' => now(),
        ]);
        AuditLog::query()->create([
            'organization_id' => $delivery->organization_id,
            'store_id' => $delivery->store_id,
            'user_id' => null,
            'action' => 'mf_daily_report_sent',
            'subject_type' => MfDailyReportDelivery::class,
            'subject_id' => $delivery->id,
            'metadata' => [
                'scope' => 'store',
                'report_date' => $report['date'],
                'source_table' => MfDailyReportSyncService::TABLE_NAME,
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        MfDailyReportDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'last_error' => mb_substr(preg_replace(
                '/https?:\/\/[^\s]+|(?:app|tbl|vew|img)[A-Za-z0-9_-]{6,}/i',
                '[redacted]',
                $exception?->getMessage() ?? '发送失败',
            ) ?: '发送失败', 0, 500),
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
