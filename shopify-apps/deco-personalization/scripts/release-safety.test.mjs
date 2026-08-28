import assert from 'node:assert/strict';
import test from 'node:test';

import {validateTestConfig} from './test-config-contract.mjs';
import {
  APPROVED_DEV_ORGANIZATION,
  APPROVED_TEST_SHOP,
  DENIED_SHOP,
  guardTestAction,
} from './test-environment-guard.mjs';

const validEnvironment = {
  PERSONALIZATION_TEST_SHOP: APPROVED_TEST_SHOP,
  PERSONALIZATION_DEV_ORGANIZATION: APPROVED_DEV_ORGANIZATION,
};

test('Test config is either a safe placeholder or a complete release contract', async () => {
  const result = await validateTestConfig();
  if (result.runnable) {
    assert.deepEqual(await validateTestConfig({requireRunnable: true}), result);
  } else {
    assert.deepEqual(result, {runnable: false, clientId: ''});
    await assert.rejects(() => validateTestConfig({requireRunnable: true}), /still an unlinked placeholder/);
  }
});

test('guard accepts only the approved Test shop and Dev Dashboard organization', () => {
  assert.equal(guardTestAction('build', validEnvironment).shop, APPROVED_TEST_SHOP);
  assert.throws(() => guardTestAction('build', {
    ...validEnvironment,
    PERSONALIZATION_TEST_SHOP: 'another-store.myshopify.com',
  }), /requires exact shop/);
  assert.throws(() => guardTestAction('build', {
    ...validEnvironment,
    PERSONALIZATION_DEV_ORGANIZATION: 'Another Organization',
  }), /requires Dev Dashboard organization/);
});

test('guard permanently rejects production shop and requires a deploy confirmation token', () => {
  assert.throws(() => guardTestAction('deploy', {
    ...validEnvironment,
    PERSONALIZATION_TEST_SHOP: DENIED_SHOP,
  }), /Permanent denied shop/);
  assert.throws(() => guardTestAction('deploy', validEnvironment), /exact release confirmation token/);
  assert.equal(guardTestAction('deploy', {
    ...validEnvironment,
    PERSONALIZATION_TEST_RELEASE_CONFIRM: 'deco-personalization-test',
  }).action, 'deploy');
});
