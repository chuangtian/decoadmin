import {spawnSync} from 'node:child_process';

import {guardTestAction} from './test-environment-guard.mjs';
import {validateTestConfig} from './test-config-contract.mjs';

const action = process.argv[2];
const commands = {
  validate: ['app', 'config', 'validate', '--config', 'test', '--json'],
  build: ['app', 'build', '--config', 'test'],
  deploy: ['app', 'deploy', '--config', 'test', '--allow-updates'],
};
if (!commands[action]) {
  throw new Error('Allowed Test Shopify actions are validate, build, and deploy.');
}

guardTestAction(action);
await validateTestConfig({requireRunnable: true});
const result = spawnSync('shopify', commands[action], {
  cwd: new URL('../', import.meta.url),
  env: process.env,
  stdio: 'inherit',
  shell: false,
});
if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
