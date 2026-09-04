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

// 只保留生产一套环境：Cloudflare 隧道已废弃，测试环境不再单独占用 Shopify App。
// 改动后端 origin 时，三处（application_url、webhook uri、redirect_urls）加上
// Laravel 的 config/instagram_feed.php 必须同步。
const productionOrigin = 'https://admin.decomkt.com';
const configurations = new Map([
  // 当前选中配置与 production 保持一致，避免漏写 --config 时推错目标。
  ['shopify.app.toml', productionOrigin],
  ['shopify.app.production.toml', productionOrigin],
]);

// 生产 App 的 client_id。两份配置指向同一个 App，必须都声明它。
// 一个 Shopify App 只有一份 application_url 与一组 webhook 地址，所以不要再新增
// 指向其他域名的配置文件：发布时会把生产地址覆盖掉。
const productionClientId = 'd3446448682d2950aa75cea4a399d50f';

// 非生产域名一旦出现在配置里，说明有人把生产 App 指向了别的环境。
const forbiddenOrigins = /trycloudflare\.com|localhost|127\.0\.0\.1|testadmin\.decomkt\.com/i;

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
  check(/^[0-9a-f]{32}$/.test(clientId), `${fileName} client_id must be a 32-character Shopify client id`);
  check(clientId === productionClientId, `${fileName} must declare the production client_id`);
  check(!forbiddenOrigins.test(contents), `${fileName} contains a forbidden non-production URL`);
}

// 已移除的环境配置不能悄悄回来：它们与生产共用同一个 App，一旦被发布就会把生产的
// application_url 与 webhook 地址覆盖成失效地址。
for (const removed of ['shopify.app.local.toml', 'shopify.app.test.toml']) {
  check(
    !existsSync(join(projectRoot, removed)),
    `${removed} was removed on purpose: it shares the production app and would overwrite its URLs on deploy`,
  );
}

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
