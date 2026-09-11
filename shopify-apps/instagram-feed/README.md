# Instagram 内容 Shopify App

这是 `instagram-feed` 的 Shopify CLI 项目，只包含 App 配置和扩展。业务后端全部在
DecoAdmin（Laravel）根项目里，本目录没有独立的 Node / Remix / Prisma 服务。

## 所有权边界

| 关注点 | 位置 |
| --- | --- |
| Shopify App 配置与扩展 | `shopify-apps/instagram-feed` |
| Admin 入口 `/shopify-app/instagram-feed` | 根项目 `ShopifyInstagramFeedAppController` |
| App Bridge 接口 `connection` / `bootstrap` | 根项目 `ShopifyInstagramFeedAppController` |
| Meta OAuth 与合规回调 | 根项目 `InstagramFeedMetaCallbackController` |
| Webhook 接收 | 根项目 `ShopifyInstagramFeedWebhookController` |
| 账号、同步、R2 转存、展示组、前台交付 | 根项目 `app/Services/InstagramFeed` |
| App Home 前端（内容管理 + 应用配置两个页签） | 根项目 `resources/js/embedded/instagram-feed` |
| 内容管理与配置接口 | 根项目 `ShopifyInstagramFeedContentController` |
| 店铺级 Meta / R2 凭证 | 根项目 `InstagramFeedStoreCredentials` + `instagram_feed_store_settings` 表 |
| DecoAdmin 后台只读运维视图 | 根项目 `resources/js/Pages/InstagramFeed/Index.vue` |
| 账号、组织、店铺、权限、数据库 | 根项目 |

`shopify app deploy` 只发布 Shopify App 版本（配置 + 扩展），**不会**部署 Laravel。
顺序永远是：先部署后端，再 deploy Shopify 配置。

## 扩展

只有一个扩展。App Home 不是扩展：它走自托管 iframe 模型，页面由 DecoAdmin 提供
（`/shopify-app/instagram-feed`）。**不要再加 `admin.app.home.render` 扩展**——它会占据
App Home 这个坑位，商家从后台导航打开看到的就是扩展而不是 iframe 页面，两者互斥。
`validate-project.mjs` 会拦住这种改动。

- `extensions/instagram-videos`：Theme App Extension。从
  `app.metafields.instagram_videos.feed` 读取内容并服务端渲染，商家在区块设置里
  填写展示组标识来指定展示哪一组。

## 环境

| 环境 | 配置文件 | 应用域名 | client_id |
| --- | --- | --- | --- |
| `test`（当前生效） | `shopify.app.test.toml` | `https://testadmin.decomkt.com` | `d3446448682d2950aa75cea4a399d50f` |
| `production`（当前停用） | `shopify.app.production.toml` | `https://admin.decomkt.com` | `d3446448682d2950aa75cea4a399d50f` |

**生产与测试是同一个 Shopify App**（handle `deco-instagram-feed`，同一个 `client_id`）。
一个 Shopify App 只有一份 `application_url` 和一组 webhook 地址，
所以两套配置永远只能有一套生效：发布测试配置就等于把生产入口停用，反之亦然。

当前状态：该 App 已指向测试服用于联调，生产入口暂时停用（生产侧没有任何店铺安装）。
`shopify.app.toml`（CLI 当前选中配置）是 `shopify.app.test.toml` 的逐字镜像；
`shopify.app.production.toml` 保持生产地址不动，它就是切回生产的还原点。

Cloudflare 隧道环境已废弃，`shopify.app.local.toml` 不允许重新出现。
`validate-project.mjs` 的闸门：每份配置的 `application_url`、两条 webhook 订阅地址与
`auth.redirect_urls` 必须同源、不得混入另一套环境的域名、不得出现隧道或本地地址，
`shopify.app.toml` 必须完整镜像某一套环境，安装必须保持 Shopify 托管
（不得出现 `use_legacy_install_flow`、不得引用 `/oauth/callback`），
且不得存在 `admin.app.home.render` 扩展。

要让测试与生产真正并行（互不停用），必须先在 Dev Dashboard 新建一个**独立的** App，
用它自己的 `client_id`，再把本目录的配置和根项目 `config/instagram_feed.php` 按环境拆开。

后端通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择环境。Client Secret
只放在未跟踪的环境变量里：`INSTAGRAM_FEED_PRODUCTION_CLIENT_SECRET`，或公共回退变量
`INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

## 常用命令

```bash
# 结构闸门 + 构建当前选中环境（test）。不依赖 Shopify 登录，随时可跑。
npm run check

# Shopify 侧配置校验，需要已登录 CLI。
npm run check:config:test
npm run check:config:production

# 构建
npm run build:test
npm run build:production
```

CLI 登录（已有会话时可非交互复用）：

```bash
shopify auth login --alias <你的 Shopify 账号邮箱>
```

发布前必须先确认对应环境的后端已就绪（先部署 DecoAdmin，再发布 Shopify 配置与扩展）：

```bash
# 指向测试服（会同时停用生产入口）
npm run deploy:test

# 指向生产（会同时停用测试入口）
npm run deploy:production
```

`deploy:test` 与 `deploy:production` 是互斥的：它们推的是同一个 App，后执行的那个生效。

以下命令仍然不提供，原因如下：

- `dev`、`build:local`、`deploy:local`：对应的隧道配置文件已删除，且与线上共用同一个
  App，一旦执行会把已发布的 `application_url` 与 webhook 地址覆盖成失效地址；
- `config:link:*`：会从 Dev Dashboard 拉取配置覆盖本地 TOML，把 `scopes` 与 webhook
  订阅冲成 Dashboard 默认值。`client_id` 一律手动填进 TOML，再用 `deploy:test` /
  `deploy:production` 反向推送（配置里设了 `include_config_on_deploy = true`，
  以本仓库的 TOML 为唯一事实来源）。

## 切换环境

因为两套配置共用一个 App，切换必须整套做完，缺一步就会出现「Shopify 指向 A、扩展跳 B」：

切回生产（测试联调完成后）：

1. `shopify.app.toml`：改成 `shopify.app.production.toml` 的逐字镜像；
3. 先把 DecoAdmin 生产后端部署到包含目标改动的提交；
4. `npm run check` 确认闸门通过（会打印当前选中环境）；
5. `npm run check:config:production`；
6. `npm run deploy:production`；
7. 测试服 `.env.staging` 里的 `INSTAGRAM_FEED_TEST_CLIENT_ID` /
   `INSTAGRAM_FEED_TEST_CLIENT_SECRET` 视情况清除，避免测试后端继续拿生产 App 凭证；
8. Meta 后台把 OAuth redirect、Deauthorize、Data deletion 回调改回生产域名。

切到测试是同样的步骤，把 production 与 test 互换即可。

## 让测试与生产真正并行

上面的「切换环境」是共用一个 App 的临时方案，同一时刻只有一个环境可用。要让两者互不影响，
必须按顺序做完以下几步，缺一步就会互相干扰：

1. 在 Dev Dashboard 新建一个**独立**的 App，取得新的 `client_id`（绝不能复用现在这个）；
2. 把 `shopify.app.test.toml` 的 `client_id` 换成新 App 的；
3. `scripts/validate-project.mjs`：把 `sharedClientId` 拆成按文件区分的 client_id 校验；
4. 根项目 `config/instagram_feed.php` 对应环境档的 `client_id` 与 `app_url`，
   以及该环境 `.env` 里的 `INSTAGRAM_FEED_<ENV>_CLIENT_ID` / `_CLIENT_SECRET`；
5. Meta 开发者后台补充该域名的 OAuth redirect URI、Deauthorize 与 Data deletion 回调。

两套环境各自独立之后，App Home 页面由各自的 DecoAdmin 环境提供，不存在共用的构建期常量，
所以不再需要"切换环境时改扩展"这一步。

在完成第 1 步拿到独立 `client_id` 之前，`deploy:test` 与 `deploy:production` 始终是互斥的。

## 安装与会话

安装由 **Shopify 托管**：配置里不声明 `use_legacy_install_flow`。
商家点安装链接后由 Shopify 弹权限授予页，装好直接打开应用，**不需要任何授权动作**。

`[auth] redirect_urls` 仍要声明（CLI schema 要求），但指向 App Home 入口
`/shopify-app/instagram-feed` 本身，不是授权回调——后端已经没有回调了。

DecoAdmin 侧的人工授权入口已经全部移除（`InstagramFeedShopifyOAuthController`、
`InstagramFeedOAuthService`、`/shopify-authorize`、`/shopify-verify`、OAuth 回调路由）。
会话的唯一建立途径是 App Bridge 的 session token 换 offline token，且在任何内容管理请求
发现会话不可用时自动补建（`InstagramFeedEmbeddedSession`）。

webhook 订阅写在 TOML 里由 Shopify 统一管理（`app/uninstalled`、`app/scopes_update`），
不再按店铺注册——这样"装了但从未打开过应用"的店铺卸载时也能收到通知。

App Home 走 Shopify 官方推荐的自托管 iframe 模型：页面由 DecoAdmin 提供
（`/shopify-app/instagram-feed`，前端在根项目 `resources/js/embedded/instagram-feed*`），
本目录只保留 App 配置与扩展。

## 商家在应用里做什么

App Home 有两个页签：

- **内容管理**：连接 Instagram 账号、同步内容、看转存进度与失败原因、建展示组并编排组内内容。
  没有「发布」按钮 —— 内容一变就自动同步到店铺前台。
- **应用配置**：填自己店铺的 Meta 应用凭证（Instagram / Facebook 至少一条）和 Cloudflare R2
  存储凭证。凭证按店铺存在 DecoAdmin 的 `instagram_feed_store_settings` 表，密钥加密且不回显，
  留空提交表示保持原值。一个店铺改自己的凭证不影响其它店铺。

同一环境下所有店铺共用同一个 Meta OAuth 回调地址，应用配置页签会把它列出并提供复制按钮，
商家要把它填进自己 Meta 应用的 OAuth 设置里。

DecoAdmin 后台那一页是**只读**的：只看连接状态、转存素材统计与失败原因、展示区列表，
没有任何编辑入口，后端也没有对应的写路由。

## 安全约定

- 不要把 Client Secret、Access Token、Meta 凭证或 R2 密钥写进 TOML、源码或文档。
  商家的 Meta / R2 凭证由他们自己在应用配置页签填写，加密存在 DecoAdmin 数据库里。
- 保持 `embedded = true`，并保持安装为 Shopify 托管：后端已经没有授权码回调可用，
  重新声明 `use_legacy_install_flow` 会让安装流程直接断掉。`validate-project.mjs` 会拦住这种改动。
- 保持自动改写 URL 关闭，避免 CLI 覆盖已部署地址。
- 不要把学生优惠或其他 Shopify App 的代码与资源放进本目录。
- 不要把 Laravel 应用、Node 后端、Prisma 或本地数据库放回本目录，
  `npm run check:project` 会拦住这些残留。
