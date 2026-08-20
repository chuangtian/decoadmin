<?php

namespace App\Services;

use App\Models\Store;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;

class AnalyticsOverviewInsightsService
{
    public function __construct(
        private ShopifyAnalyticsReportService $reports,
        private BusinessInsightsService $businessInsights,
    ) {}

    /**
     * @param  array{from: string, to: string}  $period
     * @param  array<string, int|float>  $customers
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $period, array $customers, array $filters = []): array
    {
        $native = $this->reports->analyticsOverview($store, $period['from'], $period['to']);
        $source = $native['acquisition'];
        $device = $native['devices'];
        $location = $native['locations'];
        $posLocations = $native['pos_locations'];
        $posStaff = $native['pos_staff'];
        $localPos = $this->businessInsights->localPosOverview($store, $filters);

        $nativePosAvailable = $posLocations['available'] || $posStaff['available'];
        $locations = $posLocations['available']
            ? $this->posLocations($posLocations['rows'])
            : $localPos['locations'];
        $staff = $posStaff['available']
            ? $this->posStaff($posStaff['rows'])
            : $localPos['staff'];

        return [
            'schema' => 'analytics-overview-insights-v1',
            'acquisition' => $this->reportCard($source, $this->acquisitionRows($source['rows'])),
            'devices' => $this->reportCard($device, $this->deviceRows($device['rows'])),
            'locations' => $this->reportCard($location, $this->locationRows($location['rows'])),
            'customers' => [
                'available' => true,
                'source' => (string) ($customers['source'] ?? 'local_sync'),
                'semantic_mode' => (string) ($customers['semantic_mode'] ?? 'decoadmin_custom'),
                'items' => [
                    ['key' => 'new', 'label' => '新客户', 'value' => (int) ($customers['new'] ?? 0)],
                    ['key' => 'returning', 'label' => '回头客户', 'value' => (int) ($customers['returning'] ?? 0)],
                ],
                'repeat_rate' => round((float) ($customers['repeat_rate'] ?? 0), 2),
                'error' => null,
            ],
            'pos' => [
                'available' => true,
                'source' => $nativePosAvailable ? 'shopifyql' : 'local_sync',
                'locations' => $locations,
                'staff' => $staff,
                'error' => $nativePosAvailable ? null : ($posLocations['error'] ?? $posStaff['error'] ?? null),
            ],
            'integration' => [
                'report_scope_granted' => $source['scope_granted'],
                'shopifyql_available' => collect([$source, $device, $location, $posLocations, $posStaff])
                    ->contains(fn (array $report): bool => $report['available']),
                'storage' => $native['storage'] ?? null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $report @param list<array<string, mixed>> $items */
    private function reportCard(array $report, array $items): array
    {
        return [
            'available' => (bool) $report['available'],
            'source' => 'shopifyql',
            'items' => $items,
            'error' => $report['error'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function acquisitionRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'key' => trim((string) ($row['referrer_source'] ?? '')).'|'.trim((string) ($row['referrer_name'] ?? '')),
            'label' => trim((string) ($row['referrer_name'] ?? '')) ?: trim((string) ($row['referrer_source'] ?? '')) ?: '直接访问',
            'detail' => trim((string) ($row['referrer_source'] ?? '')) ?: 'Direct',
            'sessions' => $this->integer($row['sessions'] ?? 0),
            'visitors' => $this->integer($row['online_store_visitors'] ?? 0),
            'converted_sessions' => $this->integer($row['sessions_that_completed_checkout'] ?? 0),
            'conversion_rate' => $this->percent($row['conversion_rate'] ?? 0),
        ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function deviceRows(array $rows): array
    {
        $total = max(1, collect($rows)->sum(fn (array $row): int => $this->integer($row['sessions'] ?? 0)));

        return collect($rows)->map(function (array $row) use ($total): array {
            $sessions = $this->integer($row['sessions'] ?? 0);

            return [
                'key' => (string) ($row['session_device_type'] ?? 'unknown'),
                'label' => $this->deviceLabel((string) ($row['session_device_type'] ?? 'unknown')),
                'sessions' => $sessions,
                'share' => round($sessions / $total * 100, 2),
                'pageviews' => $this->integer($row['pageviews'] ?? 0),
                'bounce_rate' => $this->percent($row['bounce_rate'] ?? 0),
                'conversion_rate' => $this->percent($row['conversion_rate'] ?? 0),
            ];
        })->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function locationRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'key' => trim((string) ($row['session_country'] ?? '')).'|'.trim((string) ($row['session_region'] ?? '')),
            'label' => trim((string) ($row['session_region'] ?? '')) ?: trim((string) ($row['session_country'] ?? '')) ?: '未知地区',
            'country' => trim((string) ($row['session_country'] ?? '')) ?: '未知国家',
            'sessions' => $this->integer($row['sessions'] ?? 0),
            'visitors' => $this->integer($row['online_store_visitors'] ?? 0),
            'conversion_rate' => $this->percent($row['conversion_rate'] ?? 0),
        ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function posLocations(array $rows): array
    {
        return collect($rows)->filter(fn (array $row): bool => filled($row['pos_location_id'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) $row['pos_location_id'],
                'name' => (string) ($row['pos_location_name'] ?? '未命名 POS 地点'),
                'orders' => $this->integer($row['orders'] ?? 0),
                'net_sales' => $this->decimal($row['net_sales'] ?? 0),
                'total_sales' => $this->decimal($row['total_sales'] ?? 0),
            ])->values()->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function posStaff(array $rows): array
    {
        return collect($rows)->filter(fn (array $row): bool => filled($row['staff_id'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) $row['staff_id'],
                'name' => (string) ($row['staff_member_name'] ?? '未命名 POS 员工'),
                'orders' => $this->integer($row['orders'] ?? 0),
                'units' => $this->integer($row['net_items_sold'] ?? 0),
                'attributed_sales' => $this->decimal($row['net_sales'] ?? 0),
            ])->values()->all();
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function percent(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return round(abs($number) <= 1 ? $number * 100 : $number, 2);
    }

    private function deviceLabel(string $value): string
    {
        return match (strtolower($value)) {
            'mobile' => '移动设备',
            'desktop' => '桌面设备',
            'tablet' => '平板设备',
            default => $value !== '' ? $value : '未知设备',
        };
    }
}
