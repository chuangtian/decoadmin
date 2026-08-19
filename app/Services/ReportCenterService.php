<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportCenterService
{
    public function __construct(
        private AnalyticsQueryService $analytics,
        private ShopifyAnalyticsReportService $shopifyReports,
    ) {}

    /** @return list<array<string, mixed>> */
    public function catalog(Store $store): array
    {
        $connection = $store->shopifyConnection;
        $reportScopeGranted = in_array('read_reports', $connection?->scopes ?? [], true);
        $reportConnectionReady = $connection && in_array($connection->status, ['connected', 'warning'], true);

        return collect($this->definitions())->map(function (array $report) use ($reportScopeGranted, $reportConnectionReady): array {
            $shopifyql = str_starts_with((string) ($report['source'] ?? ''), 'shopifyql:');

            return [
                ...Arr::only($report, [
                    'slug', 'name', 'description', 'category', 'category_label', 'icon',
                    'creator', 'last_viewed_at',
                ]),
                'data_source' => $shopifyql ? 'shopifyql' : 'local',
                'available' => ! $shopifyql || ($reportConnectionReady && $reportScopeGranted),
                'requires_scope' => $shopifyql ? 'read_reports' : null,
            ];
        })->values()->all();
    }

    /** @param array<string, mixed> $filters */
    public function detail(Store $store, string $slug, array $filters): array
    {
        $definition = $this->definitions()[$slug] ?? null;
        abort_unless($definition, 404);

        $analytics = $this->analytics->sales($store, $filters);
        $source = (string) ($definition['source'] ?? $slug);
        if (str_starts_with($source, 'shopifyql:')) {
            $native = $this->shopifyReports->report(
                $store,
                substr($source, strlen('shopifyql:')),
                (string) $analytics['period']['from'],
                (string) $analytics['period']['to'],
            );
            $dataset = $this->shopifyDataset($definition, $native['rows']);
            $integration = [
                'source' => 'shopifyql',
                'available' => $native['available'],
                'scope_granted' => $native['scope_granted'],
                'error' => $native['error'],
            ];
        } else {
            $dataset = $this->dataset($analytics, $source, $store, $filters);
            $integration = [
                'source' => 'local',
                'available' => true,
                'scope_granted' => null,
                'error' => null,
            ];
        }
        $metricKeys = array_column($definition['metrics'], 'key');
        $dimensionKeys = array_column($definition['dimensions'], 'key');
        $selectedMetric = in_array($filters['metric'] ?? null, $metricKeys, true)
            ? (string) $filters['metric'] : (string) $definition['default_metric'];
        $selectedDimension = in_array($filters['dimension'] ?? null, $dimensionKeys, true)
            ? (string) $filters['dimension'] : (string) $definition['default_dimension'];
        $selectedVisualization = in_array($filters['visualization'] ?? null, $definition['visualizations'], true)
            ? (string) $filters['visualization'] : (string) $definition['default_visualization'];

        return [
            ...Arr::only($definition, [
                'slug', 'name', 'description', 'category', 'category_label', 'icon',
                'metrics', 'dimensions', 'visualizations',
            ]),
            'period' => $analytics['period'],
            'summary' => $analytics['summary'],
            'comparisons' => $analytics['comparisons'],
            'headers' => $dataset['headers'],
            'rows' => $dataset['rows'],
            'selected' => [
                'metric' => $selectedMetric,
                'dimension' => $selectedDimension,
                'visualization' => $selectedVisualization,
            ],
            'chart' => [
                'labels' => array_map(fn (array $row): string => (string) ($row[$selectedDimension] ?? '未分类'), $dataset['rows']),
                'values' => array_map(fn (array $row): float => (float) ($row[$selectedMetric] ?? 0), $dataset['rows']),
            ],
            'integration' => $integration,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function report(Store $store, array $filters): array
    {
        $slug = match ((string) ($filters['report_type'] ?? 'sales')) {
            'products' => 'product-sales',
            'customers' => 'customer-value',
            'inventory' => 'inventory-risk',
            default => 'sales-over-time',
        };

        return $this->detail($store, $slug, $filters);
    }

    /** @param array<string, mixed> $filters */
    public function export(Store $store, array $filters, string $format, string $slug = 'sales-over-time'): StreamedResponse
    {
        $report = $this->detail($store, $slug, $filters);
        $filename = sprintf('%s-%s-%s.%s', $store->id, $report['slug'], now()->format('Ymd-His'), $format === 'excel' ? 'xls' : 'csv');

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

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            'store-overview' => $this->definition('store-overview', '商店数据概览', '汇总当前商店的商品、客户、库存 SKU、可用库存和周期订单。', 'store', '商店', 'stores',
                [['key' => 'value', 'label' => '数量', 'format' => 'number']], [['key' => 'item', 'label' => '项目']], 'value', 'item', ['bar', 'table'], 'bar'),
            'order-status' => $this->definition('order-status', '订单状态分布', '按付款状态和发货状态查看周期内订单分布。', 'orders', '订单', 'orders',
                [['key' => 'orders', 'label' => '订单数', 'format' => 'number']], [['key' => 'status', 'label' => '状态'], ['key' => 'type', 'label' => '状态类型']], 'orders', 'status', ['bar', 'table'], 'bar'),
            'financial-summary' => $this->definition('financial-summary', '销售额构成', '查看毛销售额、折扣、退款、税费、运费和净销售额。', 'finance', '财务', 'wallet',
                [['key' => 'amount', 'label' => '金额', 'format' => 'currency']], [['key' => 'item', 'label' => '财务项目']], 'amount', 'item', ['bar', 'table'], 'bar'),
            'sales-over-time' => $this->definition('sales-over-time', '销售趋势', '按日期查看净销售额、毛销售额、总销售额、退款和折扣变化。', 'sales', '销售额', 'trend',
                $this->salesMetrics(), [['key' => 'date', 'label' => '日期']], 'net_sales', 'date', ['line', 'bar', 'table'], 'line'),
            'orders-over-time' => $this->definition('orders-over-time', '订单趋势', '按日期查看订单数量和平均订单金额变化。', 'orders', '订单', 'orders',
                [['key' => 'orders', 'label' => '订单数', 'format' => 'number'], ['key' => 'average_order_value', 'label' => '平均订单金额', 'format' => 'currency']],
                [['key' => 'date', 'label' => '日期']], 'orders', 'date', ['line', 'bar', 'table'], 'line'),
            'product-sales' => $this->definition('product-sales', '商品销售排行', '按商品查看销量与净销售额排行。', 'products', '商品', 'products',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'name', 'label' => '商品']], 'net_sales', 'name', ['bar', 'table'], 'bar'),
            'vendor-sales' => $this->definition('vendor-sales', '供应商销售排行', '按供应商汇总商品销量与净销售额。', 'products', '商品', 'vendors',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'vendor', 'label' => '供应商']], 'net_sales', 'vendor', ['bar', 'table'], 'bar'),
            'product-type-sales' => $this->definition('product-type-sales', '商品分类销售排行', '按商品分类汇总销量与净销售额。', 'products', '商品', 'category',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'product_type', 'label' => '商品分类']], 'net_sales', 'product_type', ['bar', 'table'], 'bar'),
            'customer-value' => $this->definition('customer-value', '客户价值排行', '按客户累计消费金额和订单数查看高价值客户。', 'customers', '客户', 'customers',
                [['key' => 'lifetime_value', 'label' => '客户价值', 'format' => 'currency'], ['key' => 'orders', 'label' => '订单数', 'format' => 'number']],
                [['key' => 'name', 'label' => '客户']], 'lifetime_value', 'name', ['bar', 'table'], 'bar'),
            'customer-overview' => $this->definition('customer-overview', '客户构成', '查看活跃、新增、回头和复购客户构成。', 'customers', '客户', 'segments',
                [['key' => 'customers', 'label' => '客户数', 'format' => 'number'], ['key' => 'rate', 'label' => '占比', 'format' => 'percent']],
                [['key' => 'segment', 'label' => '客户群体']], 'customers', 'segment', ['donut', 'bar', 'table'], 'donut'),
            'inventory-risk' => $this->definition('inventory-risk', '库存风险', '查看可用库存、周期销量、周转估算和缺货风险。', 'inventory', '库存', 'inventory',
                [['key' => 'available', 'label' => '可用库存', 'format' => 'number'], ['key' => 'units_sold', 'label' => '周期销量', 'format' => 'number'], ['key' => 'turnover', 'label' => '周转估算', 'format' => 'number']],
                [['key' => 'sku', 'label' => 'SKU']], 'available', 'sku', ['bar', 'table'], 'table'),
            'gross-sales-over-time' => $this->derivedDefinition('gross-sales-over-time', '毛销售额随时间变化', '按日期查看折扣和退款前的商品销售额。', 'sales', '销售额', 'trend', 'sales-over-time', 'gross_sales'),
            'total-sales-over-time' => $this->derivedDefinition('total-sales-over-time', '总销售额随时间变化', '按日期查看包含运费和税费后的总销售额。', 'sales', '销售额', 'trend', 'sales-over-time', 'total_sales'),
            'discounts-over-time' => $this->derivedDefinition('discounts-over-time', '折扣随时间变化', '按日期查看订单折扣金额变化。', 'finance', '财务', 'discount', 'sales-over-time', 'discounts'),
            'refunds-over-time' => $this->derivedDefinition('refunds-over-time', '退款随时间变化', '按日期查看退款金额变化。', 'finance', '财务', 'refund', 'sales-over-time', 'refunds'),
            'taxes-over-time' => $this->derivedDefinition('taxes-over-time', '税费随时间变化', '按日期查看订单税费变化。', 'finance', '财务', 'tax', 'sales-over-time', 'taxes'),
            'shipping-over-time' => $this->derivedDefinition('shipping-over-time', '运费随时间变化', '按日期查看订单运费变化。', 'finance', '财务', 'shipping', 'sales-over-time', 'shipping'),
            'average-order-value-over-time' => $this->definition('average-order-value-over-time', '平均订单金额随时间变化', '按日期查看平均订单金额变化。', 'orders', '订单', 'orders',
                [['key' => 'average_order_value', 'label' => '平均订单金额', 'format' => 'currency'], ['key' => 'orders', 'label' => '订单数', 'format' => 'number']],
                [['key' => 'date', 'label' => '日期']], 'average_order_value', 'date', ['line', 'bar', 'table'], 'line', 'orders-over-time'),
            'units-sold-by-product' => $this->definition('units-sold-by-product', '按产品统计的售出数量', '按商品查看周期销量和净销售额。', 'products', '商品', 'products',
                [['key' => 'units', 'label' => '销量', 'format' => 'number'], ['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency']],
                [['key' => 'name', 'label' => '商品']], 'units', 'name', ['bar', 'table'], 'bar', 'product-sales'),
            'sales-by-product' => $this->definition('sales-by-product', '按产品统计的销售额', '按商品查看净销售额和销量排行。', 'products', '商品', 'products',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'name', 'label' => '商品']], 'net_sales', 'name', ['bar', 'table'], 'bar', 'product-sales'),
            'sales-by-vendor' => $this->definition('sales-by-vendor', '按供应商统计的销售额', '按供应商汇总净销售额和销量。', 'products', '商品', 'vendors',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'vendor', 'label' => '供应商']], 'net_sales', 'vendor', ['bar', 'table'], 'bar', 'vendor-sales'),
            'sales-by-product-type' => $this->definition('sales-by-product-type', '按产品分类统计的销售额', '按商品分类汇总净销售额和销量。', 'products', '商品', 'category',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'product_type', 'label' => '商品分类']], 'net_sales', 'product_type', ['bar', 'table'], 'bar', 'product-type-sales'),
            'returning-customers' => $this->definition('returning-customers', '回头客', '查看新增、回头和复购客户构成。', 'customers', '客户', 'customers',
                [['key' => 'customers', 'label' => '客户数', 'format' => 'number'], ['key' => 'rate', 'label' => '占比', 'format' => 'percent']],
                [['key' => 'segment', 'label' => '客户群体']], 'customers', 'segment', ['donut', 'bar', 'table'], 'donut', 'customer-overview'),
            'returning-customer-rate' => $this->definition('returning-customer-rate', '回头客率', '查看客户群体占比和复购表现。', 'customers', '客户', 'segments',
                [['key' => 'rate', 'label' => '占比', 'format' => 'percent'], ['key' => 'customers', 'label' => '客户数', 'format' => 'number']],
                [['key' => 'segment', 'label' => '客户群体']], 'rate', 'segment', ['donut', 'bar', 'table'], 'donut', 'customer-overview'),
            'customer-segment-analysis' => $this->definition('customer-segment-analysis', '客户群组分析', '按新增、回头和复购群体查看客户结构。', 'customers', '客户', 'segments',
                [['key' => 'customers', 'label' => '客户数', 'format' => 'number'], ['key' => 'rate', 'label' => '占比', 'format' => 'percent']],
                [['key' => 'segment', 'label' => '客户群体']], 'customers', 'segment', ['donut', 'bar', 'table'], 'donut', 'customer-overview'),
            'high-value-customers' => $this->definition('high-value-customers', '高价值客户', '按累计消费金额和订单数识别高价值客户。', 'customers', '客户', 'customers',
                [['key' => 'lifetime_value', 'label' => '客户价值', 'format' => 'currency'], ['key' => 'orders', 'label' => '订单数', 'format' => 'number']],
                [['key' => 'name', 'label' => '客户']], 'lifetime_value', 'name', ['bar', 'table'], 'bar', 'customer-value'),
            'abc-product-analysis' => $this->definition('abc-product-analysis', 'ABC 产品分析', '按净销售额和销量识别重点商品。', 'inventory', '库存', 'products',
                [['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'], ['key' => 'units', 'label' => '销量', 'format' => 'number']],
                [['key' => 'name', 'label' => '商品']], 'net_sales', 'name', ['bar', 'table'], 'bar', 'product-sales'),
            'inventory-turnover' => $this->definition('inventory-turnover', '库存周转', '按 SKU 查看库存周转估算和周期销量。', 'inventory', '库存', 'inventory',
                [['key' => 'turnover', 'label' => '周转估算', 'format' => 'number'], ['key' => 'available', 'label' => '可用库存', 'format' => 'number'], ['key' => 'units_sold', 'label' => '周期销量', 'format' => 'number']],
                [['key' => 'sku', 'label' => 'SKU']], 'turnover', 'sku', ['bar', 'table'], 'table', 'inventory-risk'),
            'low-stock-products' => $this->definition('low-stock-products', '低库存商品', '查看缺货、低库存及滞销风险 SKU。', 'inventory', '库存', 'inventory',
                [['key' => 'available', 'label' => '可用库存', 'format' => 'number'], ['key' => 'units_sold', 'label' => '周期销量', 'format' => 'number']],
                [['key' => 'sku', 'label' => 'SKU']], 'available', 'sku', ['bar', 'table'], 'table', 'inventory-risk'),
            'acquisition-by-source' => $this->definition('acquisition-by-source', '按来源统计访问获取', '按引荐来源查看访问、访客、完成结账 Session 和转化率。', 'acquisition', '获取', 'channels',
                [
                    ['key' => 'sessions', 'label' => 'Session', 'format' => 'number'],
                    ['key' => 'online_store_visitors', 'label' => '访客', 'format' => 'number'],
                    ['key' => 'sessions_that_completed_checkout', 'label' => '完成结账 Session', 'format' => 'number'],
                    ['key' => 'conversion_rate', 'label' => '转化率', 'format' => 'percent'],
                ],
                [['key' => 'referrer_source', 'label' => '来源类型'], ['key' => 'referrer_name', 'label' => '引荐来源']],
                'sessions', 'referrer_source', ['bar', 'table'], 'bar', 'shopifyql:acquisition-by-source'),
            'acquisition-by-location' => $this->definition('acquisition-by-location', '按地区统计访问获取', '按国家和地区查看访客获取与结账转化。', 'acquisition', '获取', 'location',
                [
                    ['key' => 'sessions', 'label' => 'Session', 'format' => 'number'],
                    ['key' => 'online_store_visitors', 'label' => '访客', 'format' => 'number'],
                    ['key' => 'sessions_that_completed_checkout', 'label' => '完成结账 Session', 'format' => 'number'],
                    ['key' => 'conversion_rate', 'label' => '转化率', 'format' => 'percent'],
                ],
                [['key' => 'session_country', 'label' => '国家'], ['key' => 'session_region', 'label' => '地区']],
                'sessions', 'session_country', ['bar', 'table'], 'bar', 'shopifyql:acquisition-by-location'),
            'behavior-by-device' => $this->definition('behavior-by-device', '按设备统计访问行为', '按设备查看浏览深度、停留时间、跳出率和转化率。', 'behavior', '行为', 'devices',
                [
                    ['key' => 'sessions', 'label' => 'Session', 'format' => 'number'],
                    ['key' => 'pageviews', 'label' => '页面浏览量', 'format' => 'number'],
                    ['key' => 'pageviews_per_session', 'label' => '每次访问浏览页数', 'format' => 'decimal'],
                    ['key' => 'average_session_duration', 'label' => '平均停留时间', 'format' => 'seconds'],
                    ['key' => 'bounce_rate', 'label' => '跳出率', 'format' => 'percent'],
                    ['key' => 'conversion_rate', 'label' => '转化率', 'format' => 'percent'],
                ],
                [['key' => 'session_device_type', 'label' => '设备类型']],
                'sessions', 'session_device_type', ['bar', 'table'], 'bar', 'shopifyql:behavior-by-device'),
            'behavior-by-landing-page' => $this->definition('behavior-by-landing-page', '落地页行为表现', '按落地页查看访问、加购、完成结账和转化率。', 'behavior', '行为', 'pages',
                [
                    ['key' => 'sessions', 'label' => 'Session', 'format' => 'number'],
                    ['key' => 'pageviews', 'label' => '页面浏览量', 'format' => 'number'],
                    ['key' => 'sessions_with_cart_additions', 'label' => '加购 Session', 'format' => 'number'],
                    ['key' => 'sessions_that_completed_checkout', 'label' => '完成结账 Session', 'format' => 'number'],
                    ['key' => 'conversion_rate', 'label' => '转化率', 'format' => 'percent'],
                ],
                [['key' => 'landing_page_path', 'label' => '落地页'], ['key' => 'landing_page_type', 'label' => '页面类型']],
                'sessions', 'landing_page_path', ['bar', 'table'], 'bar', 'shopifyql:behavior-by-landing-page'),
            'performance-by-page' => $this->definition('performance-by-page', '页面加载绩效', '按页面查看加载次数和 Core Web Vitals 的第 75 百分位表现。', 'performance', '绩效', 'performance',
                [
                    ['key' => 'page_loads', 'label' => '页面加载次数', 'format' => 'number'],
                    ['key' => 'lcp_p75_ms', 'label' => 'LCP P75', 'format' => 'milliseconds'],
                    ['key' => 'inp_p75_ms', 'label' => 'INP P75', 'format' => 'milliseconds'],
                    ['key' => 'p75_cls', 'label' => 'CLS P75', 'format' => 'decimal'],
                    ['key' => 'cls_poor_view_count', 'label' => 'CLS 较差浏览量', 'format' => 'number'],
                ],
                [['key' => 'page_path', 'label' => '页面'], ['key' => 'page_type', 'label' => '页面类型']],
                'page_loads', 'page_path', ['bar', 'table'], 'bar', 'shopifyql:performance-by-page'),
            'performance-by-device' => $this->definition('performance-by-device', '设备与浏览器绩效', '按设备和浏览器查看页面加载量、LCP、INP 和 CLS。', 'performance', '绩效', 'devices',
                [
                    ['key' => 'page_loads', 'label' => '页面加载次数', 'format' => 'number'],
                    ['key' => 'lcp_p75_ms', 'label' => 'LCP P75', 'format' => 'milliseconds'],
                    ['key' => 'inp_p75_ms', 'label' => 'INP P75', 'format' => 'milliseconds'],
                    ['key' => 'p75_cls', 'label' => 'CLS P75', 'format' => 'decimal'],
                ],
                [['key' => 'device_type', 'label' => '设备类型'], ['key' => 'browser_family', 'label' => '浏览器']],
                'page_loads', 'device_type', ['bar', 'table'], 'bar', 'shopifyql:performance-by-device'),
            'campaign-attributed-sales' => $this->definition('campaign-attributed-sales', '营销活动归因销售额', '按 UTM 活动查看末次点击归因销售额、订单数和平均订单金额。', 'marketing', '营销', 'campaigns',
                [
                    ['key' => 'campaign_last_click_total_sales', 'label' => '归因销售额', 'format' => 'currency'],
                    ['key' => 'campaign_last_click_order_count', 'label' => '归因订单数', 'format' => 'number'],
                    ['key' => 'campaign_last_click_total_average_order_value', 'label' => '归因平均订单金额', 'format' => 'currency'],
                ],
                [['key' => 'utm_campaign', 'label' => 'UTM 活动'], ['key' => 'utm_source', 'label' => 'UTM 来源']],
                'campaign_last_click_total_sales', 'utm_campaign', ['bar', 'table'], 'bar', 'shopifyql:campaign-attributed-sales'),
            'campaign-attributed-sessions' => $this->definition('campaign-attributed-sessions', '营销活动访问漏斗', '按 UTM 活动查看访问、访客、页面浏览、完成结账和转化率。', 'marketing', '营销', 'funnel',
                [
                    ['key' => 'campaign_sessions', 'label' => '活动 Session', 'format' => 'number'],
                    ['key' => 'campaign_online_store_visitors', 'label' => '活动访客', 'format' => 'number'],
                    ['key' => 'campaign_pageviews', 'label' => '活动页面浏览量', 'format' => 'number'],
                    ['key' => 'campaign_sessions_that_completed_checkout', 'label' => '完成结账 Session', 'format' => 'number'],
                    ['key' => 'campaign_conversion_rate', 'label' => '活动转化率', 'format' => 'percent'],
                ],
                [['key' => 'utm_campaign', 'label' => 'UTM 活动'], ['key' => 'utm_source', 'label' => 'UTM 来源']],
                'campaign_sessions', 'utm_campaign', ['bar', 'table'], 'bar', 'shopifyql:campaign-attributed-sessions'),
            'marketing-engagement-performance' => $this->definition('marketing-engagement-performance', '营销渠道投入与绩效', '按营销平台和活动查看销售额、订单、广告花费、点击和曝光。', 'marketing', '营销', 'marketing',
                [
                    ['key' => 'engagements_total_sales', 'label' => '营销销售额', 'format' => 'currency'],
                    ['key' => 'engagements_orders', 'label' => '营销订单数', 'format' => 'number'],
                    ['key' => 'engagements_ad_spend', 'label' => '广告花费', 'format' => 'currency'],
                    ['key' => 'engagements_clicks', 'label' => '点击', 'format' => 'number'],
                    ['key' => 'engagements_impressions', 'label' => '曝光', 'format' => 'number'],
                ],
                [['key' => 'marketing_platform', 'label' => '营销平台'], ['key' => 'marketing_activity_title', 'label' => '营销活动']],
                'engagements_total_sales', 'marketing_platform', ['bar', 'table'], 'bar', 'shopifyql:marketing-engagement-performance'),
        ];
    }

    /** @param list<array<string, string>> $metrics @param list<array<string, string>> $dimensions @param list<string> $visualizations */
    private function definition(string $slug, string $name, string $description, string $category, string $categoryLabel, string $icon, array $metrics, array $dimensions, string $defaultMetric, string $defaultDimension, array $visualizations, string $defaultVisualization, ?string $source = null): array
    {
        return [
            'slug' => $slug, 'name' => $name, 'description' => $description,
            'category' => $category, 'category_label' => $categoryLabel, 'icon' => $icon,
            'metrics' => $metrics, 'dimensions' => $dimensions,
            'default_metric' => $defaultMetric, 'default_dimension' => $defaultDimension,
            'visualizations' => $visualizations, 'default_visualization' => $defaultVisualization,
            'source' => $source,
            'creator' => 'DecoAdmin',
            'last_viewed_at' => null,
        ];
    }

    private function derivedDefinition(string $slug, string $name, string $description, string $category, string $categoryLabel, string $icon, string $source, string $defaultMetric): array
    {
        return $this->definition(
            $slug,
            $name,
            $description,
            $category,
            $categoryLabel,
            $icon,
            $this->salesMetrics(),
            [['key' => 'date', 'label' => '日期']],
            $defaultMetric,
            'date',
            ['line', 'bar', 'table'],
            'line',
            $source,
        );
    }

    /** @return list<array<string, string>> */
    private function salesMetrics(): array
    {
        return [
            ['key' => 'net_sales', 'label' => '净销售额', 'format' => 'currency'],
            ['key' => 'gross_sales', 'label' => '毛销售额', 'format' => 'currency'],
            ['key' => 'total_sales', 'label' => '总销售额', 'format' => 'currency'],
            ['key' => 'refunds', 'label' => '退款', 'format' => 'currency'],
            ['key' => 'discounts', 'label' => '折扣', 'format' => 'currency'],
            ['key' => 'taxes', 'label' => '税费', 'format' => 'currency'],
            ['key' => 'shipping', 'label' => '运费', 'format' => 'currency'],
        ];
    }

    /** @param array<string, mixed> $analytics @param array<string, mixed> $filters */
    private function dataset(array $analytics, string $slug, Store $store, array $filters): array
    {
        return match ($slug) {
            'store-overview' => [
                'headers' => $this->headers(['item' => ['项目', 'text'], 'value' => ['数量', 'number']]),
                'rows' => $this->storeOverview($store, $analytics),
            ],
            'order-status' => [
                'headers' => $this->headers(['type' => ['状态类型', 'text'], 'status' => ['状态', 'text'], 'orders' => ['订单数', 'number']]),
                'rows' => $this->orderStatuses($store, $filters),
            ],
            'financial-summary' => [
                'headers' => $this->headers(['item' => ['财务项目', 'text'], 'amount' => ['金额', 'currency']]),
                'rows' => collect([
                    '毛销售额' => $analytics['summary']['gross_sales'], '折扣' => -abs($analytics['summary']['discounts']),
                    '退款' => -abs($analytics['summary']['refunds']), '净销售额' => $analytics['summary']['net_sales'],
                    '运费' => $analytics['summary']['shipping'], '税费' => $analytics['summary']['taxes'],
                    '总销售额' => $analytics['summary']['total_sales'],
                ])->map(fn (float|int $amount, string $item): array => ['item' => $item, 'amount' => (float) $amount])->values()->all(),
            ],
            'sales-over-time', 'orders-over-time' => [
                'headers' => $this->headers([
                    'date' => ['日期', 'date'], 'orders' => ['订单数', 'number'], 'gross_sales' => ['毛销售额', 'currency'],
                    'net_sales' => ['净销售额', 'currency'], 'total_sales' => ['总销售额', 'currency'],
                    'refunds' => ['退款', 'currency'], 'discounts' => ['折扣', 'currency'],
                    'average_order_value' => ['平均订单金额', 'currency'],
                ]),
                'rows' => $analytics['trend'],
            ],
            'product-sales' => [
                'headers' => $this->headers(['name' => ['商品', 'text'], 'vendor' => ['供应商', 'text'], 'product_type' => ['商品分类', 'text'], 'units' => ['销量', 'number'], 'net_sales' => ['净销售额', 'currency']]),
                'rows' => $analytics['rankings']['products'],
            ],
            'vendor-sales' => ['headers' => $this->headers(['vendor' => ['供应商', 'text'], 'units' => ['销量', 'number'], 'net_sales' => ['净销售额', 'currency']]), 'rows' => $analytics['rankings']['vendors']],
            'product-type-sales' => ['headers' => $this->headers(['product_type' => ['商品分类', 'text'], 'units' => ['销量', 'number'], 'net_sales' => ['净销售额', 'currency']]), 'rows' => $analytics['rankings']['product_types']],
            'customer-value' => ['headers' => $this->headers(['name' => ['客户', 'text'], 'orders' => ['订单数', 'number'], 'lifetime_value' => ['客户价值', 'currency']]), 'rows' => $analytics['customers']['high_value']],
            'customer-overview' => ['headers' => $this->headers(['segment' => ['客户群体', 'text'], 'customers' => ['客户数', 'number'], 'rate' => ['占比', 'percent']]), 'rows' => $this->customerSegments($analytics['customers'])],
            'inventory-risk' => ['headers' => $this->headers(['sku' => ['SKU', 'text'], 'available' => ['可用库存', 'number'], 'units_sold' => ['周期销量', 'number'], 'estimated_days_cover' => ['预计可售天数', 'number'], 'turnover' => ['周转估算', 'number'], 'risk' => ['风险', 'status']]), 'rows' => $analytics['inventory']['items']],
        };
    }

    /** @param array<string, mixed> $analytics @return list<array{item: string, value: int}> */
    private function storeOverview(Store $store, array $analytics): array
    {
        $organizationId = $store->organization_id;
        $storeId = $store->getKey();
        $available = (int) DB::table('inventory_items')
            ->leftJoin('inventory_levels', 'inventory_levels.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.organization_id', $organizationId)
            ->where('inventory_items.store_id', $storeId)
            ->sum('inventory_levels.available');

        return [
            ['item' => '商品', 'value' => Product::query()->forOrganization($organizationId)->forStore($store)->count()],
            ['item' => '客户', 'value' => Customer::query()->forOrganization($organizationId)->forStore($store)->count()],
            ['item' => '库存 SKU', 'value' => InventoryItem::query()->forOrganization($organizationId)->forStore($store)->count()],
            ['item' => '可用库存', 'value' => $available],
            ['item' => '周期订单', 'value' => (int) $analytics['summary']['orders']],
        ];
    }

    /** @param array<string, mixed> $filters @return list<array{type: string, status: string, orders: int}> */
    private function orderStatuses(Store $store, array $filters): array
    {
        $statuses = $this->analytics->operationsOverview($store, $filters)['order_statuses'];

        return collect([
            '付款状态' => $statuses['financial'],
            '发货状态' => $statuses['fulfillment'],
        ])->flatMap(fn (array $rows, string $type) => collect($rows)->map(fn (array $row): array => [
            'type' => $type,
            'status' => $this->statusLabel((string) $row['status']),
            'orders' => (int) $row['total'],
        ]))->values()->all();
    }

    /** @param array<string, mixed> $definition @param list<array<string, mixed>> $rows */
    private function shopifyDataset(array $definition, array $rows): array
    {
        $columns = collect([...$definition['dimensions'], ...$definition['metrics']])
            ->unique('key')
            ->values();
        $formats = $columns->mapWithKeys(fn (array $column): array => [
            $column['key'] => $column['format'] ?? 'text',
        ])->all();

        return [
            'headers' => $columns->map(fn (array $column): array => [
                'key' => $column['key'],
                'label' => $column['label'],
                'format' => $column['format'] ?? 'text',
            ])->all(),
            'rows' => collect($rows)->map(function (array $row) use ($formats): array {
                return collect($formats)->mapWithKeys(fn (string $format, string $key): array => [
                    $key => $this->normalizeShopifyValue($row[$key] ?? null, $format),
                ])->all();
            })->all(),
        ];
    }

    private function normalizeShopifyValue(mixed $value, string $format): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($format === 'percent') {
            $number = is_numeric($value) ? (float) $value : 0.0;

            return round(abs($number) <= 1 ? $number * 100 : $number, 2);
        }

        if (in_array($format, ['currency', 'decimal', 'milliseconds', 'seconds'], true)) {
            return is_numeric($value) ? round((float) $value, 2) : 0.0;
        }

        if ($format === 'number') {
            return is_numeric($value) ? (int) round((float) $value) : 0;
        }

        return $this->redact((string) $value);
    }

    private function redact(string $value): string
    {
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value) ?? '';

        return trim(preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/', '[phone]', $value) ?? '');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => '已付款', 'pending' => '待付款', 'authorized' => '已授权',
            'partially_paid' => '部分付款', 'refunded' => '已退款',
            'partially_refunded' => '部分退款', 'fulfilled' => '已发货',
            'partial' => '部分发货', 'unfulfilled' => '未发货',
            'restocked' => '已重新入库', 'unknown', '' => '未知', default => $status,
        };
    }

    /** @param array<string, mixed> $customers @return list<array<string, mixed>> */
    private function customerSegments(array $customers): array
    {
        $active = max(1, (int) $customers['active']);

        return [
            ['segment' => '新增客户', 'customers' => (int) $customers['new'], 'rate' => round((int) $customers['new'] / $active * 100, 2)],
            ['segment' => '回头客户', 'customers' => (int) $customers['returning'], 'rate' => round((int) $customers['returning'] / $active * 100, 2)],
            ['segment' => '复购客户', 'customers' => (int) $customers['repeat_customers'], 'rate' => (float) $customers['repeat_rate']],
        ];
    }

    /** @param array<string, array{0: string, 1: string}> $values */
    private function headers(array $values): array
    {
        return collect($values)->map(fn (array $value, string $key): array => ['key' => $key, 'label' => $value[0], 'format' => $value[1]])->values()->all();
    }

    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
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
