<?php

namespace App\Support;

use App\Models\Store;

final class ShopifyReportCatalog
{
    /**
     * Shopify doesn't expose an Admin API that lists its default report
     * templates. This versioned catalog mirrors the default reports visible in
     * the 2026-08 Shopify reports experience. Data execution still uses only
     * the public ShopifyQL API.
     */
    private const VERSION = '2026-08';

    private const DEFAULT_REPORTS = <<<'CATALOG'
sessions_over_time|访问随时间变化|获取
gross_profit_by_pos_location|按 POS 地点统计的毛利润|利润率
gross_profit_by_product_variant|按产品多属性统计的毛利润|利润率
gross_profit_by_product|按产品统计的毛利润|利润率
rfm_customer_analysis|RFM 客户分析|客户
rfm_customer_list|RFM 客户列表|客户
one_time_customers|一次性客户|客户
returning_customers|回头客|客户
returning_customer_rate_over_time|回头客率随时间变化|客户
customer_cohort_analysis|客户群组分析|客户
customers_by_location|按地点划分的客户|客户
new_vs_returning_customers|新客户与回头客|客户
new_customer_sales_over_time|新客户销售额随时间变化|客户
new_customers_over_time|新客户随时间变化|客户
predicted_spend_tiers|预测消费层级|客户
abc_product_analysis|ABC 产品分析|库存
transfer_unique_skus|唯一 SKU|库存
adjustments_over_time|库存调整变化|库存
inventory_transfers|库存转移|库存
inventory_transfer_orders_and_shipments|库存转移订单和货件|库存
transfer_total_shipped|总发货量|库存
transfer_total_received|总接收量|库存
transfer_total_ordered|总订购量|库存
inventory_sold_daily_by_product|按产品划分的每日售出库存|库存
inventory_remaining_per_product|按剩余库存天数划分的产品|库存
products_by_sell_through_rate|按售出率统计的产品|库存
products_by_percentage_sold|按售出百分比划分的产品|库存
adjustments_by_reason|按盘点统计的库存调整|库存
transfer_acceptance_rate|接收率|库存
month_end_inventory_value|月底库存价值|库存
month_end_inventory_snapshot|月底库存快照|库存
orders_protected_by_shopify_protect|受 Shopify Protect 保护的订单|欺诈
orders_covered_by_shopify_protect|受 Shopify Protect 保障的订单|欺诈
canceled_due_to_fraud|因欺诈而取消|欺诈
chargeback_rate_fraud|拒付率（欺诈）|欺诈
chargeback_amount_fraud|拒付金额（欺诈）|欺诈
acceptance_rate|接受率|欺诈
high_risk_orders_rate|高风险订单率|欺诈
interaction_to_next_paint_over_time|下次绘制交互 (INP)：随时间变化|绩效
interaction_to_next_paint_by_page_url|下次绘制交互 (INP)：页面 URL|绩效
interaction_to_next_paint_by_page_type|下次绘制交互 (INP)：页面类型|绩效
largest_contentful_paint_over_time|最大内容绘制 (LCP)：随时间变化|绩效
largest_contentful_paint_by_page_url|最大内容绘制 (LCP)：页面 URL|绩效
largest_contentful_paint_by_page_type|最大内容绘制 (LCP)：页面类型|绩效
cumulative_layout_shift_over_time|累积布局偏移 (CLS)：随时间变化|绩效
cumulative_layout_shift_by_page_url|累积布局偏移 (CLS)：页面 URL|绩效
cumulative_layout_shift_by_page_type|累积布局偏移 (CLS)：页面类型|绩效
visitors_right_now|当前访客|获取
sessions_by_location|按地点划分的访问量|获取
sessions_by_referrer|按推荐人统计的访问|获取
sessions_by_social_source|按社交推荐来源统计的访问|获取
visitors_over_time|访客随时间变化|获取
shop_channel_product_impressions|Shop 渠道产品展示次数|营销
performance_by_utm_campaign|UTM 宣传活动绩效|营销
performance_by_autopilot_marketing_activities|各 Autopilot 营销活动的绩效|营销
performance_by_referring_channel|引荐渠道绩效|营销
performance_by_marketing_channel|营销渠道绩效|营销
shop_campaign_roas|Shop Campaign ROAS|行为
product_recommendations_low_engagement|互动率低的产品推荐|行为
product_recommendation_conversions_over_time|产品推荐转化随时间变化|行为
consumer_agent_assisted_sessions|在线店面客服协助的访问|行为
consumer_agent_messages|在线店面客服对话和反馈|行为
customer_behavior|客户行为|行为
conversion_rate_over_time_by_store|按商店划分的转化率变化趋势|行为
searches_by_search_query|按搜索查询（单个）统计的搜索|行为
sessions_by_landing_page|按登陆页面划分的访问量|行为
sessions_by_device_type|按设备类型统计的访问|行为
search_conversions_over_time|搜索转化随时间变化|行为
searches_with_no_clicks|无点击的搜索|行为
searches_with_no_results|无结果的搜索|行为
checkout_conversion_rate_over_time|结账转化率随时间变化|行为
bounce_rate_over_time|跳出率随时间变化|行为
conversion_rate_breakdown|转化率细分|行为
conversion_rate_over_time|转化率随时间变化|行为
order_to_fulfillment_time|从下单到发货的时间|订单
reversed_quantity_rate_over_time|冲销数量比率随时间的变化|订单
reversed_quantity_over_time|冲销数量随时间的变化|订单
orders_with_tracking_included|包含跟踪编号的订单|订单
shipping_labels_over_time|发货标签随时间变化|订单
orders_fulfilled_over_time|已发货订单随时间变化|订单
items_ordered_over_time|已订购商品随时间变化|订单
orders_delivered_over_time|已配送订单随时间变化|订单
orders_and_reversals_by_product|按产品划分的订单和冲销数量|订单
items_reversed_by_product|按产品显示的冲销商品|订单
shipping_labels|按订单统计的发货标签|订单
items_bought_together|搭配购买的商品|订单
orders_over_time|订单随时间变化|订单
order_to_delivery_speed|运输和配送绩效|订单
top_returned_products_with_reasons|退货最多的产品及原因|订单
managed_markets_taxes|Managed Markets 税款|财务
shop_pay_transactions|Shop Pay 交易|财务
shop_pay_payments|Shop Pay 支付|财务
shop_channel_orders|Shop 渠道订单|财务
payouts_over_time|交易款项随时间变化|财务
payments_over_time|净支付随时间变化|财务
store_credit_transactions|商店抵扣额交易|财务
shop_referral_orders|引荐订单|财务
shop_referral_sales|引荐销售额|财务
total_sales_breakdown|总销售额细分|财务
chargeback_rate|拒付率|财务
payments_by_method|按付款方式统计的净支付|财务
tips_by_staff_member|按员工统计的小费|财务
shop_payments_by_type|按类型划分的 Shop 付款|财务
payments_by_gateway_summary|按网关划分的付款摘要|财务
payments_by_gateway|按网关划分的净付款额|财务
net_sales_without_cost_by_order|按订单划分的不含成本净销售额|财务
payments_by_order|按订单划分的净付款额|财务
net_sales_by_order|按订单划分的净销售额|财务
shipping_by_order|按订单划分的发货|财务
net_sales_with_cost_by_order|按订单划分的含成本净销售额|财务
cost_of_goods_sold_by_order|按订单划分的售出商品成本|财务
total_sales_by_order|按订单划分的总销售额|财务
gross_profit_by_order|按订单划分的毛利润|财务
gross_sales_by_order|按订单划分的毛销售额|财务
total_sales_reversals_by_order|按订单显示的总销售冲销|财务
discounts_by_order|按订单统计的折扣|财务
outstanding_gift_card_balance|未兑换礼品卡余额|财务
outstanding_store_credit_balance|未结清商店抵扣额余额|财务
payments_received|来自 Shopify Payments 的总付款金额|财务
net_sales_from_gift_cards|来自礼品卡的净销售额|财务
gross_profit_breakdown|毛利润细分|财务
taxes|税款|财务
finance_summary|财务摘要|财务
pos_staff_orders_total|POS 员工订单总数|销售额
net_sales_over_time|净销售额随时间变化|销售额
bundle_item_versus_non_bundle_sales|套装商品与非套装商品的销售额对比|销售额
bundle_total_sales_over_time|套装总销售额随时间变化|销售额
canceled_subscriptions_over_time|已取消订阅随时间变化|销售额
average_order_quantity_over_time|平均订单数量随时间变化|销售额
average_order_value_over_time|平均订单金额随时间变化|销售额
total_sales_reversals_over_time|总销售冲销随时间的变化|销售额
total_sales_over_time|总销售额随时间变化|销售额
total_sales_by_product_variant|按产品多属性统计的总销售额|销售额
total_sales_by_product|按产品统计的总销售额|销售额
total_sales_by_vendor|按厂商统计的总销售额|销售额
units_sold_by_product_variant|按售出件数排名的热门产品多属性|销售额
total_sales_over_time_by_store|按商店划分的总销售额变化趋势|销售额
total_sales_by_bundle_component|按套装组件统计的总销售额|销售额
total_sales_by_bundle|按套装统计的总销售额|销售额
sales_by_customer_name|按客户姓名划分的销售额|销售额
average_profit_margin_by_market|按市场统计的平均利润率|销售额
agentic_total_sales_by_channel|按引荐渠道划分的智能体总销售额|销售额
sales_by_discount_codes|按折扣码划分的销售额|销售额
sales_by_referrer|按推荐人统计的总销售额|销售额
net_items_sold_by_order_referrer_source|按推荐来源统计的售出商品|销售额
sales_by_discount_codes_from_apps|按来自应用的折扣码划分的销售额|销售额
sales_by_social_source|按社交推荐来源统计的总销售额|销售额
profit_margin_by_order|按订单统计的利润率|销售额
total_sales_by_billing_location|按账单地点统计的总销售额|销售额
total_sales_by_currency|按货币统计的总销售额|销售额
gross_sales_by_sales_channel|按销售渠道划分的毛销售额|销售额
net_sales_by_sales_channel|按销售渠道统计的净销售额|销售额
total_sales_by_sales_channel|按销售渠道统计的总销售额|销售额
new_vs_returning_customers_over_time|新客户与回头客对比随时间变化|销售额
new_vs_returning_customer_sales|新客户与回头客的销售额对比|销售额
new_subscriptions_over_time|新订阅随时间变化|销售额
active_subscriptions_over_time|有效订阅随时间变化|销售额
weekly_sales_patterns|每周销售模式|销售额
gross_sales_over_time|毛销售额（按时间）|销售额
shopify_tax_us_country|美国销售税|销售额
subscription_vs_one_time_sales|订阅销售额与一次性销售额对比|销售额
subscriptions_sales_over_time|订阅销售额随时间变化|销售额
sales_reversals_over_time|销售冲销随时间的变化|销售额
agentic_total_sales_over_time|随时间变化的智能体总销售额|销售额
pos_staff_daily_sales_total|POS 员工每日销售总额|零售销售额
pos_staff_sales_total|POS 员工销售总额|零售销售额
total_sales_by_pos_location|按 POS 地点统计的总销售额|零售销售额
pos_total_sales_by_product_variant|按产品多属性统计的 POS 总销售额|零售销售额
pos_total_sales_by_product_type|按产品类型统计的 POS 总销售额|零售销售额
pos_total_sales_by_product|按产品统计的 POS 总销售额|零售销售额
pos_total_sales_by_vendor|按厂商统计的 POS 总销售额|零售销售额
pos_total_sales_by_staff_member|按员工统计的 POS 总销售额|零售销售额
CATALOG;

    /** Reports created inside this store aren't enumerable through Admin API. */
    private const STORE_REPORTS = [
        'macfoxebike.myshopify.com' => [
            ['349110637', '按登陆页面划分的跳出率&平均访问持续时间', '获取', '. Climber'],
            ['389874029', '活动页随时间变化', '获取', 'climber climber'],
            ['439484781', '6月 总销售额', '销售额', 'WangJingbin'],
            ['334266733', 'Copy of Total sales over time by store', '销售额', 'WangShirley'],
            ['295633261', 'Orders by order utm source', '销售额', '账户已停用'],
        ],
    ];

    /** @return list<array<string, string>> */
    public function entries(Store $store): array
    {
        $defaults = collect(explode("\n", trim(self::DEFAULT_REPORTS)))
            ->map(function (string $line): array {
                [$slug, $name, $category] = explode('|', $line, 3);

                return $this->entry($slug, $name, $category, 'Shopify', 'shopify_default');
            });

        $storeReports = collect(self::STORE_REPORTS[$store->shopify_domain] ?? [])
            ->map(fn (array $report): array => $this->entry(
                $report[0], $report[1], $report[2], $report[3], 'shopify_custom',
            ));

        return $defaults->concat($storeReports)->values()->all();
    }

    /** @return array<string, string> */
    private function entry(string $slug, string $name, string $category, string $creator, string $kind): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'category' => $this->categoryKey($category),
            'category_label' => $category,
            'creator' => $creator,
            'kind' => $kind,
            'catalog_version' => self::VERSION,
        ];
    }

    private function categoryKey(string $category): string
    {
        return [
            '获取' => 'acquisition',
            '利润率' => 'profit',
            '客户' => 'customers',
            '库存' => 'inventory',
            '欺诈' => 'fraud',
            '绩效' => 'performance',
            '营销' => 'marketing',
            '行为' => 'behavior',
            '订单' => 'orders',
            '财务' => 'finance',
            '销售额' => 'sales',
            '零售销售额' => 'retail_sales',
        ][$category] ?? 'other';
    }
}
