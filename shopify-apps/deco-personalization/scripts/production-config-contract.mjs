import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const configPath = path.join(appRoot, 'shopify.app.production.toml');
const runtimePath = path.join(appRoot, 'extensions/app-home/src/runtime.mjs');
const EXPECTED_SCOPES = ['read_customer_events', 'read_discounts', 'write_app_proxy', 'write_discounts', 'write_pixels'];

export async function validateProductionConfig({requireRunnable = false} = {}) {
  const config = await readFile(configPath, 'utf8');
  const runtime = await readFile(runtimePath, 'utf8');
  const clientId = value(config, 'client_id');
  const runnableAssignments = /^\s*(?:client_id|name|handle|application_url|embedded|scopes|redirect_urls|url|uri|prefix|subpath)\s*=/m;

  if (!clientId) {
    check(!runnableAssignments.test(config), 'Production placeholder contains partial runnable configuration');
    check(!requireRunnable, 'Production Shopify configuration is still an unlinked placeholder');
    return {runnable: false, clientId: ''};
  }

  check(/^[a-f0-9]{32}$/i.test(clientId), 'Production Client ID must be a 32-character Shopify client identifier');
  check(value(config, 'name') === 'Deco 个性化推荐', 'Unexpected Production merchant-facing name');
  check(value(config, 'handle') === 'deco-personalization', 'Unexpected Production technical handle');
  check(value(config, 'application_url') === 'https://admin.decomkt.com/shopify-app/personalization', 'Unexpected Production application URL');
  check(/^\s*embedded\s*=\s*true\s*$/m.test(config), 'Production app must remain embedded');
  check(/^\s*automatically_update_urls_on_dev\s*=\s*false\s*$/m.test(config), 'Production URLs must never be changed by a local dev command');
  check(/^\s*api_version\s*=\s*"2026-07"\s*$/m.test(config), 'Unexpected Production webhook API version');

  const scopes = value(config, 'scopes').split(',').map((scope) => scope.trim()).filter(Boolean).sort();
  check(JSON.stringify(scopes) === JSON.stringify(EXPECTED_SCOPES), `Production scopes must be exactly ${EXPECTED_SCOPES.join(',')}`);
  check(/^\s*use_legacy_install_flow\s*=\s*false\s*$/m.test(config), 'Production must use Shopify managed installation');
  check(config.includes('topics = [ "app/uninstalled" ]'), 'Missing app/uninstalled subscription');
  check(config.includes('topics = [ "app/scopes_update" ]'), 'Missing app/scopes_update subscription');
  check(config.includes('compliance_topics = [ "customers/data_request", "customers/redact", "shop/redact" ]'), 'Missing mandatory compliance topics');
  check(count(config, 'https://admin.decomkt.com/api/shopify-app/personalization/webhooks') >= 3, 'All Production webhook subscriptions must use the Production endpoint');
  check(config.includes('redirect_urls = [ "https://admin.decomkt.com/shopify-app/personalization" ]'), 'Unexpected Production redirect URL');
  check(config.includes('url = "https://admin.decomkt.com/api/shopify-app/personalization/proxy"'), 'Unexpected Production App Proxy target');
  check(config.includes('prefix = "apps"'), 'Unexpected Production App Proxy prefix');
  check(config.includes('subpath = "deco-personalization"'), 'Unexpected Production App Proxy subpath');
  check(runtime.includes(`['${clientId}', {`), 'App Home runtime must include the same Production Client ID');
  check(runtime.includes("appOrigin: 'https://admin.decomkt.com'"), 'App Home runtime must use the Production backend origin');
  check(!/trycloudflare\.com|https:\/\/testadmin\.decomkt\.com/i.test(config), 'Production configuration contains a Local or Test URL');

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
  const result = await validateProductionConfig({requireRunnable: process.argv.includes('--require-runnable')});
  process.stdout.write(result.runnable
    ? 'Production Shopify configuration contract passed.\n'
    : 'Production Shopify configuration placeholder contract passed.\n');
}
