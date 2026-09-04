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
| 账号、同步、R2 转存、展示组、发布 | 根项目 `app/Services/InstagramFeed` |
| 后台页面 | 根项目 `resources/js/Pages/InstagramFeed` |
| 账号、组织、店铺、权限、数据库 | 根项目 |

`shopify app deploy` 只发布 Shopify App 版本（配置 + 扩展），**不会**部署 Laravel。
顺序永远是：先部署后端，再 deploy Shopify 配置。

## 扩展

- `extensions/app-home`：Admin UI 扩展。检查店铺是否已接入 DecoAdmin，调用
  `bootstrap` 建立本 App 的 Shopify 会话，然后把用户引导到 DecoAdmin。
- `extensions/instagram-videos`：Theme App Extension。从
  `app.metafields.instagram_videos.feed` 读取内容并服务端渲染，商家在区块设置里
  填写展示组标识来指定展示哪一组。

## 环境

| 环境 | Handle | 应用域名 | client_id |
| --- | --- | --- | --- |
| `production` | `deco-instagram-feed` | `https://admin.decomkt.com` | `d3446448682d2950aa75cea4a399d50f` |

只保留生产一套环境。Cloudflare 隧道不再维护，测试环境也不再单独占用 Shopify App，
原先的 `shopify.app.local.toml` 与 `shopify.app.test.toml` 已删除，
`shopify.app.toml`（CLI 当前选中配置）与 `shopify.app.production.toml` 声明同一个
`client_id`、同一个域名。

**不要再新增指向其他域名的配置文件。** 一个 Shopify App 只有一份 `application_url`
与一组 webhook 地址，任何指向隧道或 `testadmin.decomkt.com` 的配置一旦被发布，都会把
生产地址覆盖掉，导致生产中断。`validate-project.mjs` 已加入两道闸门：配置里出现非生产
域名会失败，被删除的那两个文件重新出现也会失败。

要恢复独立的测试或本地环境，必须先在 Dev Dashboard 新建一个**独立的** App，用它自己的
`client_id`，再新增对应配置文件，并同步 `extensions/app-home/src/runtime.mjs` 的
`APP_ENVIRONMENTS` 映射和根项目 `config/instagram_feed.php`。

后端通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择环境。Client Secret
只放在未跟踪的环境变量里：`INSTAGRAM_FEED_PRODUCTION_CLIENT_SECRET`，或公共回退变量
`INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

## 常用命令

```bash
# 结构闸门 + 构建。不依赖 Shopify 登录，随时可跑。
npm run check

# Shopify 侧配置校验，需要已登录 CLI。
npm run check:config:production

# 构建
npm run build:production
```

CLI 登录（已有会话时可非交互复用）：

```bash
shopify auth login --alias <你的 Shopify 账号邮箱>
```

发布前必须先确认后端已就绪（先部署 DecoAdmin，再发布 Shopify 配置与扩展）：

```bash
npm run deploy:production
```

以下命令已移除，原因如下：

- `dev`、`build:local`、`deploy:local`、`deploy:test` 等非生产命令：它们对应的配置
  文件已删除，且与生产共用同一个 App，一旦执行会把生产的 `application_url` 与 webhook
  地址覆盖成失效地址；
- `config:link:*`：会从 Dev Dashboard 拉取配置覆盖本地 TOML，把 `scopes` 与 webhook
  订阅冲成 Dashboard 默认值。`client_id` 一律手动填进 TOML，再用 `deploy:production`
  反向推送（配置里设了 `include_config_on_deploy = true`，以本仓库的 TOML 为唯一事实来源）。

## 新增非生产环境

当前只有生产一套。要加回测试或本地环境，必须按顺序做完以下几步，缺一步就会与生产互相干扰：

1. 在 Dev Dashboard 新建一个**独立**的 App，取得新的 `client_id`（绝不能复用生产那个）；
2. 新增对应的 `shopify.app.<env>.toml`，其中 `application_url`、两条
   `webhooks.subscriptions.uri`、`auth.redirect_urls` 都指向该环境的后端域名；
3. `scripts/validate-project.mjs`：把新文件加入 `configurations`，并调整
   `productionClientId` 相关校验与 `forbiddenOrigins`，同时移除对该文件「不得存在」的闸门；
4. `extensions/app-home/src/runtime.mjs` 的 `APP_ENVIRONMENTS`：登记新 `client_id`
   与对应的 `appOrigin`，否则扩展会报「无法识别当前应用环境」；
5. 根项目 `config/instagram_feed.php` 对应环境档的 `client_id` 与 `app_url`，
   以及该环境 `.env` 里的 `INSTAGRAM_FEED_<ENV>_CLIENT_ID` / `_CLIENT_SECRET`；
6. `package.json` 中加入该环境的 `build:` / `deploy:` 命令；
7. Meta 开发者后台补充该域名的 OAuth redirect URI、Deauthorize 与 Data deletion 回调。

在完成第 1 步拿到独立 `client_id` 之前，绝对不要新增指向其他域名的配置文件并执行
`shopify app deploy`：生产 App 的 `application_url` 与 webhook 地址会被覆盖，生产立即中断。

## 安全约定

- 不要把 Client Secret、Access Token、Meta 凭证或 R2 密钥写进 TOML、源码或文档。
- 保持 `embedded = true` 与 `use_legacy_install_flow = false`：安装由 Shopify 托管，
  会话通过 App Bridge session token 走 token exchange。
- 保持自动改写 URL 关闭，避免 CLI 覆盖已部署地址。
- 不要把学生优惠或其他 Shopify App 的代码与资源放进本目录。
- 不要把 Laravel 应用、Node 后端、Prisma 或本地数据库放回本目录，
  `npm run check:project` 会拦住这些残留。
