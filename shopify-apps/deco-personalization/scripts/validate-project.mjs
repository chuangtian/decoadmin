import {readFile, readdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const requiredFiles = [
  'AGENTS.md',
  'README.md',
  'TEST_RELEASE.md',
  'package.json',
  'shopify.app.toml',
  'shopify.app.local.toml',
  'shopify.app.test.toml',
  'shopify.app.production.toml',
  'extensions/README.md',
  'extensions/app-home/shopify.extension.toml',
  'extensions/app-home/src/AppHome.jsx',
  'extensions/app-home/src/runtime.mjs',
  'extensions/recommendations/shopify.extension.toml',
  'extensions/recommendations/blocks/recommendations.liquid',
  'extensions/recommendations/blocks/smart_cart.liquid',
  'extensions/recommendations/assets/recommendations.js',
  'extensions/recommendations/assets/recommendations.css',
  'extensions/recommendations/assets/smart-cart.js',
  'extensions/recommendations/assets/smart-cart.css',
  'extensions/web-pixel/package.json',
  'extensions/web-pixel/shopify.extension.toml',
  'extensions/web-pixel/src/index.js',
  'scripts/test-config-contract.mjs',
  'scripts/test-environment-guard.mjs',
  'scripts/run-test-shopify-action.mjs',
  'scripts/release-safety.test.mjs',
];
const failures = [];

for (const file of requiredFiles) {
  await read(file);
}

const packageJson = JSON.parse(await read('package.json'));
const scripts = packageJson.scripts ?? {};
check(packageJson.name === 'deco-personalization', 'package name must be deco-personalization');
check(packageJson.private === true, 'package must remain private');
check(typeof scripts.check === 'string', 'check script is required');
check(typeof scripts['check:project'] === 'string', 'check:project script is required');
check(typeof scripts.test === 'string', 'test script is required');
check(typeof scripts['validate:test'] === 'string', 'validate:test script is required');
check(typeof scripts['build:test'] === 'string', 'build:test script is required');
check(typeof scripts['deploy:test'] === 'string', 'deploy:test script is required');

for (const [name, command] of Object.entries(scripts)) {
  check(!/local/i.test(name), `Local script is forbidden: ${name}`);
  check(!/--config\s+local\b/i.test(String(command)), `Local Shopify config command is forbidden: ${name}`);
  check(!/deploy:production|--config\s+production\b/i.test(`${name} ${command}`), `Production deploy command is forbidden: ${name}`);
  check(!/shopify\s+app\s+dev\b/i.test(String(command)), `Shopify Local dev command is forbidden: ${name}`);
}

const localConfig = await read('shopify.app.local.toml');
const productionConfig = await read('shopify.app.production.toml');
const nonRunnablePattern = /^\s*(client_id|application_url|embedded|name|handle|scopes|redirect_urls|url|uri|prefix|subpath)\s*=/m;
check(!nonRunnablePattern.test(localConfig), 'Local placeholder contains runnable Shopify configuration');
check(!nonRunnablePattern.test(productionConfig), 'Production placeholder contains runnable Shopify configuration');

const files = await walk(appRoot);
for (const absolutePath of files) {
  const relativePath = path.relative(appRoot, absolutePath).replaceAll(path.sep, '/');
  if (relativePath === 'package-lock.json') continue;
  const contents = await readFile(absolutePath, 'utf8').catch(() => '');
  const isValidator = relativePath === 'scripts/validate-project.mjs';
  const isReleaseSafety = [
    'scripts/test-environment-guard.mjs',
    'scripts/release-safety.test.mjs',
  ].includes(relativePath);

  check(!/trycloudflare\.com/i.test(contents), `Temporary Cloudflare URL is forbidden: ${relativePath}`);
  if (!['AGENTS.md', 'README.md', 'TEST_RELEASE.md', 'scripts/validate-project.mjs'].includes(relativePath)) {
    if (relativePath === 'extensions/app-home/src/runtime.mjs') {
      check(/macfoxebike\.myshopify\.com/i.test(contents), 'App Home runtime must preserve the permanent denied shop guard');
    } else if (relativePath !== 'extensions/app-home/src/runtime.test.mjs' && !isReleaseSafety) {
      check(!/macfoxebike/i.test(contents), `Denied shop may appear only in the dedicated safety guard: ${relativePath}`);
    }
    if (!isReleaseSafety) {
      check(!/macfox-test-app/i.test(contents), `Test shop must not be hardcoded in runtime files: ${relativePath}`);
    }
  }
  if (!isValidator) {
    check(!/shopify-apps\/(student-discount|instagram-feed|commerce-hub)/i.test(contents), `Another Shopify App path is forbidden: ${relativePath}`);
    check(!/read_discounts|write_discounts|student_discount|instagram_feed/i.test(contents), `Another App business scope is forbidden: ${relativePath}`);
  }
  check(!/(client_secret|access_token|refresh_token)\s*=\s*["'][^"']+["']/i.test(contents), `Committed secret-like value is forbidden: ${relativePath}`);
}

if (failures.length > 0) {
  failures.forEach((failure) => process.stderr.write(`✗ ${failure}\n`));
  process.exitCode = 1;
} else {
  process.stdout.write('deco-personalization project validation passed.\n');
}

function check(condition, message) {
  if (!condition) failures.push(message);
}

async function read(relativePath) {
  try {
    return await readFile(path.join(appRoot, relativePath), 'utf8');
  } catch {
    failures.push(`Missing required file: ${relativePath}`);
    return '';
  }
}

async function walk(directory) {
  const entries = await readdir(directory, {withFileTypes: true});
  const nested = await Promise.all(entries.map(async (entry) => {
    if (entry.name === 'node_modules' || entry.name === '.shopify' || entry.name === 'dist') return [];
    const absolutePath = path.join(directory, entry.name);
    return entry.isDirectory() ? walk(absolutePath) : [absolutePath];
  }));

  return nested.flat();
}
