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
  if (!contents.includes('Non-runnable')) throw new Error(`${file} must stay a non-runnable placeholder until configured`);
}
for (const entry of readdirSync(root)) {
  if (/^\.env($|\.)/.test(entry)) throw new Error('Environment files must stay untracked');
}
console.log('Deco Referral project foundation is valid.');
