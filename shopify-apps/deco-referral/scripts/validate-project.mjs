import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const required = ['AGENTS.md', 'README.md', 'package.json', 'shopify.app.local.toml', 'shopify.app.test.toml', 'shopify.app.production.toml', 'extensions'];
for (const item of required) {
  if (!existsSync(join(root, item))) throw new Error(`${item} is missing`);
}
for (const file of ['shopify.app.local.toml', 'shopify.app.test.toml', 'shopify.app.production.toml']) {
  const contents = readFileSync(join(root, file), 'utf8');
  if (/client_secret|access_token|refresh_token/i.test(contents)) throw new Error(`${file} contains a credential field`);
  if (file !== 'shopify.app.test.toml' && !contents.includes('Non-runnable')) throw new Error(`${file} must stay a non-runnable placeholder until configured`);
  if (file === 'shopify.app.test.toml' && (!contents.includes('https://testadmin.decomkt.com/shopify-app/referral') || !contents.includes('44ddcba51c2e4f3eec425aae1d2cbe22'))) throw new Error('Test App identity and URL must remain isolated');
}
for (const entry of readdirSync(root)) {
  if (/^\.env($|\.)/.test(entry)) throw new Error('Environment files must stay untracked');
}
const testConfig = readFileSync(join(root, 'shopify.app.test.toml'), 'utf8');
if (testConfig.includes('[[webhooks.subscriptions]]') && !testConfig.includes('uri = \"https://testadmin.decomkt.com/api/shopify-app/referral/webhooks\"')) throw new Error('Referral webhook must use the absolute test URL; CLI joins relative paths to the App Home path');
console.log('Deco Referral project foundation is valid.');
