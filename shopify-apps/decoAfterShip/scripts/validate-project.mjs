import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const expectedScopes = 'read_products,read_inventory,read_orders,read_customers,read_locations,read_reports,read_discounts,write_discounts';
const configurations = new Map([
  ['shopify.app.toml', 'https://testadmin.decomkt.com'],
  ['shopify.app.test.toml', 'https://testadmin.decomkt.com'],
  ['shopify.app.production.toml', 'https://admin.decomkt.com'],
]);

function check(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function walk(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    if (['node_modules', '.shopify'].includes(entry.name)) {
      return [];
    }

    const absolutePath = join(directory, entry.name);

    return entry.isDirectory() ? walk(absolutePath) : [absolutePath];
  });
}

for (const [fileName, origin] of configurations) {
  const filePath = join(projectRoot, fileName);
  check(existsSync(filePath), `${fileName} is missing`);

  const contents = readFileSync(filePath, 'utf8');
  check(contents.includes(`application_url = "${origin}/shopify/launch"`), `${fileName} has the wrong Shopify-facing launch URL`);
  check(contents.includes(`"${origin}/shopify/oauth/callback"`), `${fileName} has the wrong OAuth callback URL`);
  check(contents.includes('embedded = false'), `${fileName} must remain non-embedded`);
  check(contents.includes(`scopes = "${expectedScopes}"`), `${fileName} scopes differ from Laravel config/shopify.php`);
  check(contents.includes('use_legacy_install_flow = true'), `${fileName} must preserve Laravel's OAuth flow`);
  check(contents.includes('automatically_update_urls_on_dev = false'), `${fileName} must not rewrite deployed URLs`);
  check(!/trycloudflare\.com|localhost|127\.0\.0\.1/i.test(contents), `${fileName} contains a forbidden local URL`);
  check(!/client_secret|access_token/i.test(contents), `${fileName} contains a private credential field`);
}

check(!existsSync(join(projectRoot, 'shopify.app.local.toml')), 'A local Shopify App configuration must not exist');
check(existsSync(join(projectRoot, 'extensions')), 'The extensions directory is missing');
check(!existsSync(join(projectRoot, 'artisan')), 'The Laravel application must not be copied into the Shopify CLI project');
check(!existsSync(join(projectRoot, 'composer.json')), 'The Laravel application must not be copied into the Shopify CLI project');

for (const filePath of walk(projectRoot)) {
  const path = relative(projectRoot, filePath).replaceAll('\\', '/').toLowerCase();
  check(!path.includes('student-discount') && !path.includes('student_discount'), `Unrelated Student Discount file found: ${path}`);
}

console.log('decoAfterShip Shopify CLI project structure is valid.');
