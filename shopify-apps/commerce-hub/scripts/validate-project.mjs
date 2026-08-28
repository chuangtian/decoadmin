import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const repositoryRoot = dirname(dirname(projectRoot));
const expectedScopes = [
  'read_products',
  'read_inventory',
  'read_orders',
  'read_customers',
  'read_locations',
  'read_reports',
];
const expectedBackendScopes = expectedScopes.join(',');
const expectedSortedScopes = [...expectedScopes].sort();

const configurations = new Map([
  ['shopify.app.toml', {
    clientId: '8060ab64cae19ff20a05814f1276d716',
    origin: 'https://consistent-menu-herself-telephony.trycloudflare.com',
    embedded: false,
  }],
  ['shopify.app.local.toml', {
    clientId: '8060ab64cae19ff20a05814f1276d716',
    origin: 'https://consistent-menu-herself-telephony.trycloudflare.com',
    embedded: false,
  }],
  ['shopify.app.test.toml', {
    clientId: 'c6921ca2233c5069033577d3cd1759ea',
    origin: 'https://testadmin.decomkt.com',
    embedded: true,
  }],
  ['shopify.app.production.toml', {
    clientId: '52f415f03423fe1f1838dcfa7a0fca9a',
    origin: 'https://admin.decomkt.com',
    embedded: true,
  }],
]);

const forbiddenPaths = [
  'app',
  'build',
  'prisma',
  'public',
  '.react-router',
  'Dockerfile',
  'vite.config.ts',
  'tsconfig.json',
  'env.d.ts',
  '.graphqlrc.ts',
  '.eslintrc.cjs',
  'shopify.web.toml',
];

function check(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function quotedValue(contents, key) {
  return new RegExp(`^${key}\\s*=\\s*"([^"]*)"`, 'm').exec(contents)?.[1] ?? '';
}

function walk(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    if (['node_modules', '.shopify', '.git', 'dist'].includes(entry.name)) {
      return [];
    }

    const absolutePath = join(directory, entry.name);

    return entry.isDirectory() ? walk(absolutePath) : [absolutePath];
  });
}

for (const [fileName, expected] of configurations) {
  const filePath = join(projectRoot, fileName);
  check(existsSync(filePath), `${fileName} is missing`);

  const contents = readFileSync(filePath, 'utf8');
  const scopes = quotedValue(contents, 'scopes').split(',').map((scope) => scope.trim()).filter(Boolean).sort();
  check(quotedValue(contents, 'client_id') === expected.clientId, `${fileName} has the wrong client_id`);
  check(quotedValue(contents, 'application_url') === expected.origin, `${fileName} has the wrong application URL`);
  check(contents.includes(`embedded = ${expected.embedded}`), `${fileName} has the wrong embedded mode`);
  check(
    JSON.stringify(scopes) === JSON.stringify(expectedSortedScopes),
    `${fileName} must declare exactly the Commerce Hub scope set`,
  );
  check(contents.includes('use_legacy_install_flow = true'), `${fileName} must use the authorization-code install flow`);
  check(
    contents.includes(`redirect_urls = ["${expected.origin}/shopify/oauth/callback"]`),
    `${fileName} has the wrong OAuth callback URL`,
  );
  check(contents.includes('api_version = "2026-07"'), `${fileName} has the wrong Shopify API version`);
  check(contents.includes('include_config_on_deploy = true'), `${fileName} must deploy its versioned configuration`);
  check(contents.includes('automatically_update_urls_on_dev = false'), `${fileName} must not rewrite URLs automatically`);
  check(!/read_discounts|write_discounts/.test(contents), `${fileName} contains Student Discount scopes`);
  check(!/client_secret|access_token|refresh_token/i.test(contents), `${fileName} contains a private credential field`);
}

const uniqueClientIds = new Set(
  [...configurations.values()].map(({ clientId }) => clientId),
);
check(uniqueClientIds.size === 3, 'Local, test, and production must use three distinct Shopify Apps');

const envExample = readFileSync(join(repositoryRoot, '.env.example'), 'utf8');
check(
  new RegExp(`^SHOPIFY_REQUESTED_SCOPES=${expectedBackendScopes}$`, 'm').test(envExample),
  '.env.example Commerce Hub scopes do not match Shopify App configuration',
);

const backendConfig = readFileSync(join(repositoryRoot, 'config/shopify.php'), 'utf8');
check(
  backendConfig.includes(`env('SHOPIFY_REQUESTED_SCOPES', '${expectedBackendScopes}')`),
  'config/shopify.php fallback scopes do not match Shopify App configuration',
);

for (const path of forbiddenPaths) {
  check(!existsSync(join(projectRoot, path)), `Backend or unrelated project file is forbidden here: ${path}`);
}

check(existsSync(join(projectRoot, 'extensions')), 'The extensions directory is missing');
check(!existsSync(join(projectRoot, 'artisan')), 'The Laravel application must stay in the repository root');
check(!existsSync(join(projectRoot, 'composer.json')), 'Composer dependencies must stay in the repository root');

for (const filePath of walk(projectRoot)) {
  const path = relative(projectRoot, filePath).replaceAll('\\', '/');
  check(!/(^|\/)\.env($|\.)/.test(path), `Environment files must stay untracked: ${path}`);
  check(!path.endsWith('.sqlite'), `Local databases are forbidden in this Shopify App: ${path}`);
  check(!path.toLowerCase().includes('student-discount'), `Student Discount files are forbidden here: ${path}`);
  check(!path.toLowerCase().includes('instagram-feed'), `Instagram Feed files are forbidden here: ${path}`);
}

console.log('Commerce Hub Shopify configuration and backend scopes are aligned.');
