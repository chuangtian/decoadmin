import {spawnSync} from 'node:child_process';

import {guardProductionAction} from './production-environment-guard.mjs';
import {validateProductionConfig} from './production-config-contract.mjs';

const action = process.argv[2];
const commands = {
  validate: ['app', 'config', 'validate', '--config', 'production', '--json'],
  build: ['app', 'build', '--config', 'production'],
  deploy: ['app', 'deploy', '--config', 'production', '--allow-updates'],
};
if (!commands[action]) {
  throw new Error('Allowed Production Shopify actions are validate, build, and deploy.');
}

guardProductionAction(action);
await validateProductionConfig({requireRunnable: true});
const result = spawnSync('shopify', commands[action], {
  cwd: new URL('../', import.meta.url),
  env: process.env,
  stdio: 'inherit',
  shell: false,
});
if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
