import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope, nextTick, reactive } from 'vue';
import { useDeferredReport } from '../../resources/js/composables/useDeferredReport.ts';

function setup(t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const previousWindow = globalThis.window;
    globalThis.window = {};
    t.after(() => {
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    });
    const state = reactive({ key: 'store-1:period-1', pending: true });
    const scope = effectScope();
    const requests = [];
    const poll = scope.run(() => useDeferredReport(() => state, ['report'], (options) => requests.push(options)));
    t.after(() => scope.stop());
    return { state, scope, requests, poll };
}

test('server rendering never schedules browser reloads', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const scope = effectScope();
    const requests = [];
    scope.run(() => useDeferredReport(() => ({ key: 'ssr', pending: true }), ['report'], (options) => requests.push(options)));
    t.mock.timers.tick(60000);
    assert.equal(requests.length, 0);
    scope.stop();
});

test('a pending report loads automatically and stops when data arrives', async (t) => {
    const { state, requests, poll } = setup(t);
    t.mock.timers.tick(2999);
    assert.equal(requests.length, 0);
    t.mock.timers.tick(1);
    assert.deepEqual(requests[0].only, ['report']);
    state.pending = false;
    await nextTick();
    requests[0].onFinish();
    t.mock.timers.tick(60000);
    assert.equal(requests.length, 1);
    assert.equal(poll.refreshing.value, false);
});

test('slow requests never overlap and polling is bounded with an explicit retry', (t) => {
    const { requests, poll } = setup(t);
    t.mock.timers.tick(3000);
    t.mock.timers.tick(60000);
    assert.equal(requests.length, 1);
    for (let i = 0; i < 20; i++) {
        requests[i].onFinish();
        t.mock.timers.tick(3000);
    }
    assert.equal(requests.length, 20);
    assert.equal(poll.timedOut.value, true);
    assert.equal(poll.refreshing.value, false);
    poll.retry();
    t.mock.timers.tick(3000);
    assert.equal(requests.length, 21);
});

test('leaving the page prevents later requests, including after an in-flight response', (t) => {
    const { requests, scope } = setup(t);
    t.mock.timers.tick(3000);
    scope.stop();
    requests[0].onFinish();
    t.mock.timers.tick(60000);
    assert.equal(requests.length, 1);
});

test('a changed store or date range starts a new bounded attempt sequence', async (t) => {
    const { requests, poll, state } = setup(t);
    for (let i = 0; i < 20; i++) {
        t.mock.timers.tick(3000);
        requests[i].onFinish();
    }
    assert.equal(poll.timedOut.value, true);
    state.key = 'store-2:period-2';
    await nextTick();
    assert.equal(poll.timedOut.value, false);
    t.mock.timers.tick(3000);
    assert.equal(requests.length, 21);
});
