<?php

namespace App\Services;

use App\Models\Store;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportCenterService
{
    public function __construct(private AnalyticsQueryService $analytics) {}

    /** @param array<string, mixed> $filters */
    public function report(Store $store, array $filters): array
    {
        $type = (string) ($filters['report_type'] ?? 'sales');
        $analytics = $this->analytics->sales($store, $filters);
        $dataset = $this->dataset($analytics, $type);

        return [
            'type' => $type,
            'period' => $analytics['period'],
            'summary' => $analytics['summary'],
            'comparisons' => $analytics['comparisons'],
            'headers' => $dataset['headers'],
            'rows' => $dataset['rows'],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function export(Store $store, array $filters, string $format): StreamedResponse
    {
        $report = $this->report($store, $filters);
        $filename = sprintf('%s-%s-%s.%s', $store->id, $report['type'], now()->format('Ymd-His'), $format === 'excel' ? 'xls' : 'csv');

        return response()->streamDownload(function () use ($report, $format): void {
            if ($format === 'excel') {
                $this->writeExcel($report['headers'], $report['rows']);

                return;
            }

            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_column($report['headers'], 'label'));
            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_map(fn (string $key): mixed => $this->safeCell($row[$key] ?? null), array_column($report['headers'], 'key')));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => $format === 'excel' ? 'application/vnd.ms-excel; charset=UTF-8' : 'text/csv; charset=UTF-8']);
    }

    /** @param array<string, mixed> $analytics */
    private function dataset(array $analytics, string $type): array
    {
        return match ($type) {
            'products' => [
                'headers' => $this->headers(['name' => '商品', 'vendor' => '供应商', 'product_type' => '商品分类', 'units' => '销量', 'net_sales' => '净销售额']),
                'rows' => $analytics['rankings']['products'],
            ],
            'customers' => [
                'headers' => $this->headers(['name' => '客户', 'orders' => '订单数', 'lifetime_value' => '客户价值']),
                'rows' => $analytics['customers']['high_value'],
            ],
            'inventory' => [
                'headers' => $this->headers(['sku' => 'SKU', 'available' => '可用库存', 'units_sold' => '周期销量', 'estimated_days_cover' => '预计可售天数', 'turnover' => '周转估算', 'risk' => '风险']),
                'rows' => $analytics['inventory']['items'],
            ],
            default => [
                'headers' => $this->headers(['date' => '日期', 'orders' => '订单数', 'net_sales' => '净销售额', 'refunds' => '退款']),
                'rows' => $analytics['trend'],
            ],
        };
    }

    /** @param array<string, string> $values */
    private function headers(array $values): array
    {
        return collect($values)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all();
    }

    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@]/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    /** @param list<array{key: string, label: string}> $headers @param list<array<string, mixed>> $rows */
    private function writeExcel(array $headers, array $rows): void
    {
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body><table><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>'.e($header['label']).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<td>'.e((string) $this->safeCell($row[$header['key']] ?? '')).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
    }
}
