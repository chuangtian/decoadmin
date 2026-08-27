import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 结构闸门：确保这个目录始终是「纯 Shopify CLI 项目」。
 *
 * 业务后端已经全部搬到 DecoAdmin（Laravel），这里只保留 App 配置和扩展。
 * 一旦有人把 Remix / Prisma / Node 后端重新塞回来，或把某个环境的 URL 指错，
 * 这个脚本就会失败。
 */

const projectRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const expectedScopes = 'read_products';

// 每套配置对应的后端 origin。local 的隧道地址会变，改的时候三处（application_url、
// webhook uri、redirect_urls）加上 Laravel 的 config/instagram_feed.php 必须同步。
const configurations = new Map([
  ['shopify.app.toml', 'https://wendy-interim-classic-segment.trycloudflare.com'],
  ['shopify.app.local.toml', 'https://wendy-interim-classic-segment.trycloudflare.com'],
  ['shopify.app.test.toml', 'https://testadmin.decomkt.com'],
  ['shopify.app.production.toml', 'https://admin.decomkt.com'],
]);

// local 必须有 client_id 才能开发。
const requiresClientId = new Set(['shopify.app.toml', 'shopify.app.local.toml']);

// 本地开发 App 的 client_id。测试与生产要么还没创建（留空），要么必须是另一个 App，
// 绝不能等于这个值 —— 那意味着某个环境被指向了本地开发 App。
const localClientId = 'd3446448682d2950aa75cea4a399d50f';

// Remix / Prisma / Node 后端的残留物。任何一个存在都说明后端又被搬回来了。
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
  'package-lock.json',
];

function check(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
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

for (const [fileName, origin] of configurations) {
  const filePath = join(projectRoot, fileName);
  check(existsSync(filePath), `${fileName} is missing`);

  const contents = readFileSync(filePath, 'utf8');
  check(
    contents.includes(`application_url = "${origin}/shopify-app/instagram-feed"`),
    `${fileName} has the wrong Shopify-facing application URL`,
  );
  check(
    contents.includes(`uri = "${origin}/api/shopify-app/instagram-feed/webhooks"`),
    `${fileName} has the wrong webhook endpoint`,
  );
  check(contents.includes(`"${origin}/api/shopify-app/auth/callback"`), `${fileName} has the wrong auth callback URL`);
  check(contents.includes('embedded = true'), `${fileName} must stay embedded`);
  check(contents.includes(`scopes = "${expectedScopes}"`), `${fileName} scopes differ from config/instagram_feed.php`);
  check(contents.includes('use_legacy_install_flow = false'), `${fileName} must use Shopify managed installation`);
  check(
    contents.includes('automatically_update_urls_on_dev = false'),
    `${fileName} must not rewrite deployed URLs`,
  );
  check(!/client_secret|access_token/i.test(contents), `${fileName} contains a private credential field`);

  const clientId = /client_id\s*=\s*"(.*)"/.exec(contents)?.[1] ?? '';
  if (requiresClientId.has(fileName)) {
    check(clientId === localClientId, `${fileName} must declare the local development client_id`);
  } else {
    // 空表示 Dev Dashboard 里还没建这个 App，`config link` 之后会填上。
    check(
      clientId === '' || /^[0-9a-f]{32}$/.test(clientId),
      `${fileName} client_id must be empty or a 32-character Shopify client id`,
    );
    check(clientId !== localClientId, `${fileName} must not point at the local development app`);
    check(!/trycloudflare\.com|localhost|127\.0\.0\.1/i.test(contents), `${fileName} contains a forbidden local URL`);
  }
}

// 三套配置必须是三个不同的 App。
const linkedClientIds = [...configurations.keys()]
  .filter((fileName) => !requiresClientId.has(fileName))
  .map((fileName) => /client_id\s*=\s*"(.*)"/.exec(readFileSync(join(projectRoot, fileName), 'utf8'))?.[1] ?? '')
  .filter(Boolean);
check(
  new Set(linkedClientIds).size === linkedClientIds.length,
  'Test and production must be linked to different Shopify apps',
);

for (const path of forbiddenPaths) {
  check(!existsSync(join(projectRoot, path)), `Backend leftover found and must not return to this project: ${path}`);
}

check(existsSync(join(projectRoot, 'extensions')), 'The extensions directory is missing');
check(!existsSync(join(projectRoot, 'artisan')), 'The Laravel application must not be copied into the Shopify CLI project');
check(!existsSync(join(projectRoot, 'composer.json')), 'The Laravel application must not be copied into the Shopify CLI project');

for (const filePath of walk(projectRoot)) {
  const path = relative(projectRoot, filePath).replaceAll('\\', '/');
  check(!/(^|\/)\.env($|\.)/.test(path), `Environment files must stay untracked and out of this project: ${path}`);
  check(!path.endsWith('.sqlite'), `Local databases belong to DecoAdmin, not this project: ${path}`);
  check(
    !path.toLowerCase().includes('student-discount') && !path.toLowerCase().includes('student_discount'),
    `Unrelated Student Discount file found: ${path}`,
  );
}

console.log('instagram-feed Shopify CLI project structure is valid.');
