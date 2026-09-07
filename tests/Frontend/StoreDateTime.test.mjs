import assert from 'node:assert/strict';
import test from 'node:test';
import { formatStoreDateTime, formatStoreDateTimeInput, formatStoreClock, safeTimezone, serverTimeAfter } from '../../resources/js/utils/storeDateTime.ts';

const instant = '2026-09-05T00:00:00Z';
for (const [timezone, local, clock] of [
    ['America/Los_Angeles', '2026-09-04T17:00', '9月4日 周五 17:00:00'],
    ['America/New_York', '2026-09-04T20:00', '9月4日 周五 20:00:00'],
    ['Asia/Shanghai', '2026-09-05T08:00', '9月5日 周六 08:00:00'],
    ['UTC', '2026-09-05T00:00', '9月5日 周六 00:00:00'],
]) {
    test(`clock, list and input share ${timezone}`, () => {
        assert.equal(formatStoreDateTimeInput(instant, timezone), local);
        assert.equal(formatStoreDateTime(instant, timezone), local.replace('T', ' '));
        assert.equal(formatStoreClock(new Date(instant), timezone), clock);
    });
}

test('daylight saving changes are calculated from the instant, not a fixed offset', () => {
    assert.equal(formatStoreDateTimeInput('2026-12-05T00:00:00Z', 'America/Los_Angeles'), '2026-12-04T16:00');
    assert.equal(formatStoreDateTimeInput('2026-03-08T09:59:00Z', 'America/Los_Angeles'), '2026-03-08T01:59');
    assert.equal(formatStoreDateTimeInput('2026-03-08T10:00:00Z', 'America/Los_Angeles'), '2026-03-08T03:00');
});

test('invalid or missing inputs do not crash the page', () => {
    assert.equal(safeTimezone(null), 'UTC');
    assert.equal(safeTimezone('invalid/timezone'), 'UTC');
    assert.equal(formatStoreDateTimeInput(instant, 'invalid/timezone'), '2026-09-05T00:00');
    assert.equal(formatStoreDateTimeInput(null, 'UTC'), '');
    assert.equal(formatStoreDateTime('not-a-date', 'UTC'), '—');
});

test('server clock is unaffected by an incorrect computer wall clock', () => {
    const original = Date.now;
    try {
        Date.now = () => Date.parse('2030-01-01T00:00:00Z');
        assert.equal(serverTimeAfter(instant, 2500).toISOString(), '2026-09-05T00:00:02.500Z');
        assert.equal(serverTimeAfter(instant, -20).toISOString(), '2026-09-05T00:00:00.000Z');
        assert.equal(serverTimeAfter(null, 1000).getTime(), Date.now());
    } finally {
        Date.now = original;
    }
});
