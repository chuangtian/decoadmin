import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createGoogleAdsTrendChart, googleTrendSalesLabel } from '../../resources/js/utils/googleAdsTrend.ts';

test('sales bars and scale use conversion-date value while attribution revenue stays unchanged', () => {
    const source = Object.freeze([
        Object.freeze({ date: '2026-09-08', spend: 3991.99, revenue: 22691.82, conversion_value: 30696.35, roi: 7.69 }),
        Object.freeze({ date: '2026-09-10', spend: 4838.79, revenue: 9820.50, conversion_value: 20837.18, roi: 4.31 }),
    ]);
    const chart = createGoogleAdsTrendChart(source);
    assert.equal(chart.amountMax, 30696.35 * 1.12);
    for (const [i, point] of chart.points.entries()) {
        assert.equal(point.sales, source[i].conversion_value);
        assert.equal(point.revenue, source[i].revenue);
        assert.equal(point.salesY, chart.top + chart.plotHeight - point.sales / chart.amountMax * chart.plotHeight);
        assert.equal(point.roi, source[i].roi);
        assert.ok(point.salesY < chart.top + chart.plotHeight - point.revenue / chart.amountMax * chart.plotHeight);
    }
    assert.equal(googleTrendSalesLabel, '销售额（按转化日期）');
});

test('empty and zero conversion-date data remain finite without falling back to attribution revenue', () => {
    assert.deepEqual(createGoogleAdsTrendChart([]).points, []);
    const chart = createGoogleAdsTrendChart([{ date: '2026-09-14', spend: 0, revenue: 200, conversion_value: 0, roi: 0 }]);
    assert.equal(chart.amountMax, 1.12);
    assert.equal(chart.points[0].sales, 0);
    assert.equal(chart.points[0].salesY, chart.top + chart.plotHeight);
    assert.ok(chart.ticks.every(tick => Object.values(tick).every(Number.isFinite)));
});

test('overview and trend templates both bind the shared sales basis for bars and tooltips', () => {
    const component = readFileSync(new URL('../../resources/js/Pages/PaidAdvertising/Channel.vue', import.meta.url), 'utf8');
    assert.match(component, /createGoogleAdsTrendChart\(overview\.value\?\.trend \?\? \[\]\)/);
    assert.equal((component.match(/:y="point\.salesY"/g) ?? []).length, 2);
    assert.equal((component.match(/money\(activeTrendPoint\.sales\)/g) ?? []).length, 2);
    assert.equal((component.match(/\{\{ googleTrendSalesLabel \}\}/g) ?? []).length, 4);
    assert.doesNotMatch(component, /point\.revenueY|activeTrendPoint\.revenue/);
});
