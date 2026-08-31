import assert from 'node:assert/strict';
import test from 'node:test';

import {validateProductionConfig} from './production-config-contract.mjs';
import {APPROVED_DEV_ORGANIZATION, guardProductionAction} from './production-environment-guard.mjs';

const validEnvironment = {
  PERSONALIZATION_DEV_ORGANIZATION: APPROVED_DEV_ORGANIZATION,
};

test('Production config is either a safe placeholder or a complete release contract', async () => {
  const result = await validateProductionConfig();
  if (result.runnable) {
    assert.deepEqual(await validateProductionConfig({requireRunnable: true}), result);
  } else {
    assert.deepEqual(result, {runnable: false, clientId: ''});
    await assert.rejects(() => validateProductionConfig({requireRunnable: true}), /still an unlinked placeholder/);
  }
});

test('Production guard accepts only the approved organization and exact deploy confirmation', () => {
  assert.equal(guardProductionAction('build', validEnvironment).organization, APPROVED_DEV_ORGANIZATION);
  assert.throws(() => guardProductionAction('build', {
    PERSONALIZATION_DEV_ORGANIZATION: 'Another Organization',
  }), /requires Dev Dashboard organization/);
  assert.throws(() => guardProductionAction('deploy', validEnvironment), /exact release confirmation token/);
  assert.equal(guardProductionAction('deploy', {
    ...validEnvironment,
    PERSONALIZATION_PRODUCTION_RELEASE_CONFIRM: 'deco-personalization-production',
  }).action, 'deploy');
});
