import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const configPath = path.join(appRoot, 'shopify.app.test.toml');
const runtimePath = path.join(appRoot, 'extensions/app-home/src/runtime.mjs');
const EXPECTED_SCOPES = ['read_customer_events', 'read_discounts', 'write_app_proxy', 'write_discounts', 'write_pixels'];

export async function validateTestConfig({requireRunnable = false} = {}) {
  const config = await readFile(configPath, 'utf8');
  const runtime = await readFile(runtimePath, 'utf8');
  const clientId = value(config, 'client_id');
  const runnableAssignments = /^\s*(?:client_id|name|handle|application_url|embedded|scopes|redirect_urls|url|uri|prefix|subpath)\s*=/m;

  if (!clientId) {
    check(!runnableAssignments.test(config), 'Test placeholder contains partial runnable configuration');
    check(!requireRunnable, 'Test Shopify configuration is still an unlinked placeholder');
    return {runnable: false, clientId: ''};
  }

  check(/^[a-f0-9]{32}$/i.test(clientId), 'Test Client ID must be a 32-character Shopify client identifier');
  check(value(config, 'name') === 'Deco 个性化推荐测试', 'Unexpected Test merchant-facing name');
  check(value(config, 'handle') === 'deco-personalization-test', 'Unexpected Test technical handle');
  check(value(config, 'application_url') === 'https://testadmin.decomkt.com/shopify-app/personalization', 'Unexpected Test application URL');
  check(/^\s*embedded\s*=\s*true\s*$/m.test(config), 'Test app must remain embedded');
  check(/^\s*automatically_update_urls_on_dev\s*=\s*false\s*$/m.test(config), 'Test URLs must never be changed by a local dev command');
  check(/^\s*api_version\s*=\s*"2026-07"\s*$/m.test(config), 'Unexpected Test webhook API version');

  const scopes = value(config, 'scopes').split(',').map((scope) => scope.trim()).filter(Boolean).sort();
  check(JSON.stringify(scopes) === JSON.stringify(EXPECTED_SCOPES), `Test scopes must be exactly ${EXPECTED_SCOPES.join(',')}`);
  check(/^\s*use_legacy_install_flow\s*=\s*false\s*$/m.test(config), 'Test must use Shopify managed installation');
  check(config.includes('topics = [ "app/uninstalled" ]'), 'Missing app/uninstalled subscription');
  check(config.includes('topics = [ "app/scopes_update" ]'), 'Missing app/scopes_update subscription');
  check(config.includes('compliance_topics = [ "customers/data_request", "customers/redact", "shop/redact" ]'), 'Missing mandatory compliance topics');
  check(count(config, 'https://testadmin.decomkt.com/api/shopify-app/personalization/webhooks') >= 3, 'All Test webhook subscriptions must use the Personalization endpoint');
  check(config.includes('redirect_urls = [ "https://testadmin.decomkt.com/shopify-app/personalization" ]'), 'Unexpected Test redirect URL');
  check(config.includes('url = "https://testadmin.decomkt.com/api/shopify-app/personalization/proxy"'), 'Unexpected Test App Proxy target');
  check(config.includes('prefix = "apps"'), 'Unexpected Test App Proxy prefix');
  check(config.includes('subpath = "deco-personalization-test"'), 'Unexpected Test App Proxy subpath');
  check(runtime.includes(`const TEST_CLIENT_ID = '${clientId}';`), 'App Home runtime must pin the same Test Client ID');
  check(!/trycloudflare\.com|https:\/\/admin\.decomkt\.com/i.test(config), 'Test configuration contains a Local or Production URL');

  return {runnable: true, clientId};
}

function value(contents, key) {
  const match = contents.match(new RegExp(`^\\s*${key}\\s*=\\s*"([^"]*)"\\s*$`, 'm'));
  return match?.[1] ?? '';
}

function count(contents, needle) {
  return contents.split(needle).length - 1;
}

function check(condition, message) {
  if (!condition) throw new Error(message);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const result = await validateTestConfig({requireRunnable: process.argv.includes('--require-runnable')});
  process.stdout.write(result.runnable
    ? 'Test Shopify configuration contract passed.\n'
    : 'Test Shopify configuration placeholder contract passed.\n');
}
