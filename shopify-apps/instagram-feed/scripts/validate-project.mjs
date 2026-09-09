import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 结构闸门：确保这个目录始终是「纯 Shopify CLI 项目」，且当前选中的环境自上而下一致。
 *
 * 业务后端已经全部搬到 DecoAdmin（Laravel），这里只保留 App 配置和扩展。
 * 一旦有人把 Remix / Prisma / Node 后端重新塞回来，或把某个环境的 URL 指错，
 * 这个脚本就会失败。
 */

const projectRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const expectedScopes = 'read_products';

// 生产与测试是同一个 Shopify App（同一个 client_id）。一个 App 只有一份
// application_url 与一组 webhook 地址，所以两套配置永远只能有一套生效：
// 发布测试配置＝生产入口停用，反之亦然。改动某套环境的 origin 时，
// 三处（application_url、webhook uri、redirect_urls）加上 Laravel 的
// config/instagram_feed.php 必须同步。
const productionOrigin = 'https://admin.decomkt.com';
const testOrigin = 'https://testadmin.decomkt.com';
const environmentByOrigin = new Map([
  [productionOrigin, 'production'],
  [testOrigin, 'test'],
]);
const configurations = new Map([
  ['shopify.app.production.toml', productionOrigin],
  ['shopify.app.test.toml', testOrigin],
]);

// CLI 当前选中配置。它必须逐项镜像上面某一套环境，避免漏写 --config 时推错目标。
const selectedConfiguration = 'shopify.app.toml';

// 生产与测试共用的 client_id。所有配置文件都必须声明它。
const sharedClientId = 'd3446448682d2950aa75cea4a399d50f';

// 隧道与本地地址一律不允许出现：对应的环境配置已删除，且它们会覆盖线上地址。
const forbiddenOrigins = /trycloudflare\.com|localhost|127\.0\.0\.1/i;

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

/** 一份配置文件必须完整指向同一个 origin，并保持安装方式与授权集合不变。 */
function assertConfiguration(fileName, origin) {
  const filePath = join(projectRoot, fileName);
  check(existsSync(filePath), `${fileName} is missing`);

  const contents = readFileSync(filePath, 'utf8');
  check(
    contents.includes(`application_url = "${origin}/shopify-app/instagram-feed"`),
    `${fileName} has the wrong Shopify-facing application URL`,
  );
  // 授权码安装流程下 Shopify 拒绝 app 级 webhook 订阅：
  // app/uninstalled 与 app/scopes_update 由 DecoAdmin 按店铺注册。
  check(
    ! contents.includes('[[webhooks.subscriptions]]'),
    `${fileName} must not declare app-specific webhook subscriptions while use_legacy_install_flow is enabled`,
  );
  check(contents.includes('api_version = "'), `${fileName} must pin the webhook api_version`);
  check(
    contents.includes(`"${origin}/shopify-app/instagram-feed/oauth/callback"`),
    `${fileName} has the wrong Shopify OAuth callback URL`,
  );
  check(contents.includes('embedded = true'), `${fileName} must stay embedded`);
  check(contents.includes(`scopes = "${expectedScopes}"`), `${fileName} scopes differ from config/instagram_feed.php`);
  // DecoAdmin 通过授权码回调建立本 App 的 offline token（与 Commerce Hub 一致）。
  // 改成 Shopify 托管安装前必须先把后端换回 token exchange。
  check(contents.includes('use_legacy_install_flow = true'), `${fileName} must keep the authorization-code install flow`);
  check(
    contents.includes('automatically_update_urls_on_dev = false'),
    `${fileName} must not rewrite deployed URLs`,
  );
  check(!/client_secret|access_token/i.test(contents), `${fileName} contains a private credential field`);

  const clientId = /client_id\s*=\s*"(.*)"/.exec(contents)?.[1] ?? '';
  check(/^[0-9a-f]{32}$/.test(clientId), `${fileName} client_id must be a 32-character Shopify client id`);
  check(clientId === sharedClientId, `${fileName} must declare the shared Instagram Feed client_id`);
  check(!forbiddenOrigins.test(contents), `${fileName} contains a forbidden tunnel or localhost URL`);

  // 一份配置里不能混进另一套环境的地址，否则发布出去的地址是两个环境的拼接。
  for (const [otherOrigin] of environmentByOrigin) {
    if (otherOrigin !== origin) {
      check(!contents.includes(otherOrigin), `${fileName} must not mix in the ${environmentByOrigin.get(otherOrigin)} origin`);
    }
  }

  return contents;
}

for (const [fileName, origin] of configurations) {
  assertConfiguration(fileName, origin);
}

// 选中配置：先判断它指向哪套环境，再用同一套规则校验，保证它是某套环境的完整镜像。
const selectedContents = readFileSync(join(projectRoot, selectedConfiguration), 'utf8');
const selectedOrigins = [...environmentByOrigin.keys()].filter((origin) => selectedContents.includes(origin));
check(
  selectedOrigins.length === 1,
  `${selectedConfiguration} must mirror exactly one environment origin, found ${selectedOrigins.length}`,
);
const selectedOrigin = selectedOrigins[0];
const selectedEnvironment = environmentByOrigin.get(selectedOrigin);
assertConfiguration(selectedConfiguration, selectedOrigin);

// 扩展里的 appOrigin 是构建期常量，会随 App 版本一起发布，
// 因此必须与选中配置指向同一个环境，否则商家会被引导到另一个后端。
const runtimePath = join(projectRoot, 'extensions/app-home/src/runtime.mjs');
check(existsSync(runtimePath), 'extensions/app-home/src/runtime.mjs is missing');
const runtimeContents = readFileSync(runtimePath, 'utf8');
check(
  runtimeContents.includes(`appOrigin: '${selectedOrigin}'`),
  `runtime.mjs appOrigin must match ${selectedConfiguration} (${selectedEnvironment}: ${selectedOrigin})`,
);
check(
  runtimeContents.includes(`environment: '${selectedEnvironment}'`),
  `runtime.mjs environment must match ${selectedConfiguration} (${selectedEnvironment})`,
);
check(
  runtimeContents.includes(sharedClientId),
  'runtime.mjs must map the shared Instagram Feed client_id',
);

// 本地隧道环境已废弃：配置文件不能悄悄回来。
check(
  !existsSync(join(projectRoot, 'shopify.app.local.toml')),
  'shopify.app.local.toml was removed on purpose: the Cloudflare tunnel environment is no longer maintained',
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

console.log(`instagram-feed Shopify CLI project structure is valid. Selected environment: ${selectedEnvironment} (${selectedOrigin}).`);
