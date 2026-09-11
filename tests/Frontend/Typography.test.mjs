import assert from 'node:assert/strict';
import test from 'node:test';
import { readableChartFontSize, readableMapTextSize } from '../../resources/js/utils/typography.ts';

test('chart labels stay at least 12px after responsive viewBox scaling', () => {
    for (const scale of [0.25, 0.5, 0.75, 1, 1.5, 2]) {
        for (const authored of [12, 14, 20]) {
            const adjusted = readableChartFontSize(authored, scale);
            assert.ok(adjusted * scale >= 12);
            assert.ok(adjusted >= authored);
        }
    }
    assert.equal(readableChartFontSize(20, 1), 20);
    assert.equal(readableChartFontSize(12, 0), 12);
});

test('map font minimum changes output sizes without changing zoom breakpoints', () => {
    const interpolate = ['interpolate', ['linear'], ['zoom'], 0, 8, 10, 14];
    assert.deepEqual(readableMapTextSize(interpolate), ['interpolate', ['linear'], ['zoom'], 0, 12, 10, 14]);
    assert.deepEqual(interpolate, ['interpolate', ['linear'], ['zoom'], 0, 8, 10, 14]);
    assert.deepEqual(readableMapTextSize(['step', ['zoom'], 9, 5, 10, 12, 18]), ['step', ['zoom'], 12, 5, 12, 12, 18]);
    assert.deepEqual(readableMapTextSize(['get', 'labelSize']), ['max', 12, ['get', 'labelSize']]);
    assert.deepEqual(readableMapTextSize(readableMapTextSize(['get', 'labelSize'])), ['max', 12, ['get', 'labelSize']]);
    assert.deepEqual(readableMapTextSize({ stops: [[0, 8], [10, 16]] }), { stops: [[0, 12], [10, 16]] });
});
