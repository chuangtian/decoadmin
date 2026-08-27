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
| `local`（已停用） | `deco-instagram-feed-local` | 隧道已废弃 | 与 `test` 共用同一个 App |
| `test` | `deco-instagram-feed-test` | `https://testadmin.decomkt.com` | `d3446448682d2950aa75cea4a399d50f` |
| `production` | `deco-instagram-feed` | `https://admin.decomkt.com` | 需创建独立 App 后填入 |

`local` 环境已停用：Cloudflare 隧道不再维护，原本的本地开发 App 已转为测试环境专用，
因此 `shopify.app.toml`、`shopify.app.local.toml`、`shopify.app.test.toml` 声明同一个
`client_id`，`shopify.app.toml` 也改为指向 test。

一个 Shopify App 只有一份 `application_url` 与一组 webhook 地址，共用之后该 App 固定
指向 `https://testadmin.decomkt.com`。**不要执行 `shopify app deploy --config local`**，
那会把地址改回失效的隧道地址，直接打断测试环境；`deploy:local` 已从 npm scripts 移除。
要恢复本地开发，必须先申请新的隧道地址并在 Dev Dashboard 新建一个独立 App。

生产环境仍必须是 Dev Dashboard 里另一个独立的 Shopify App，App ID、Secret、URL、数据
和发布动作都不可与测试互换。生产的 `client_id` 故意留空，避免误把生产配置指向测试 App。

后端通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择环境。Client Secret
只放在未跟踪的环境变量里：`INSTAGRAM_FEED_<ENV>_CLIENT_SECRET`，或公共回退变量
`INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

## 常用命令

```bash
# 结构闸门 + 构建 test。不依赖 Shopify 登录，随时可跑。
npm run check

# Shopify 侧配置校验，需要已登录 CLI。
npm run check:config:test
npm run check:config:production   # 生产 App 创建并填入 client_id 后才能跑

# 按环境构建
npm run build:test
npm run build:production
```

CLI 登录（已有会话时可非交互复用）：

```bash
shopify auth login --alias <你的 Shopify 账号邮箱>
```

发布需要显式指定环境，且必须先确认后端已就绪：

```bash
npm run deploy:test
npm run deploy:production
```

`local` 相关命令（`dev`、`build:local`、`deploy:local`、`config:link:test`）已移除：

- `deploy:local` 会把共用 App 的地址改回失效隧道，打断测试环境；
- `config:link:*` 会从 Dev Dashboard 拉取配置覆盖本地 TOML，把 `scopes` 与 webhook
  订阅冲成 Dashboard 默认值。新环境的 `client_id` 一律手动填进对应 TOML，
  再用 `deploy:*` 反向推送（各配置都设了 `include_config_on_deploy = true`，
  以本仓库的 TOML 为唯一事实来源）。

## 恢复本地开发

`local` 已停用，与 `test` 共用同一个 Shopify App。恢复独立的本地环境需要按顺序做完
以下几步，缺一步就会与测试环境互相干扰：

1. 在 Dev Dashboard 新建一个独立 App，取得新的 `client_id`；
2. 申请新的隧道地址，同步到 `shopify.app.local.toml` 的 3 处：`application_url`、
   两条 `webhooks.subscriptions.uri`、`auth.redirect_urls`；
3. `scripts/validate-project.mjs`：把 `shopify.app.local.toml` 从
   `requiresSharedClientId` 中移出，并恢复它在 `configurations` 里的隧道 origin；
4. 根项目 `config/instagram_feed.php` 的 local `app_url` 与 `client_id`；
5. 在 `package.json` 中重新加入 `dev`、`build:local`、`deploy:local`；
6. Meta 开发者后台补充该隧道地址的 OAuth redirect URI、Deauthorize 与
   Data deletion 回调地址。

在完成第 1 步之前，绝对不要对 `local` 执行 `shopify app deploy`：共用 App 的
`application_url` 与 webhook 地址会被改回隧道地址，测试环境立即失效。

## 安全约定

- 不要把 Client Secret、Access Token、Meta 凭证或 R2 密钥写进 TOML、源码或文档。
- 保持 `embedded = true` 与 `use_legacy_install_flow = false`：安装由 Shopify 托管，
  会话通过 App Bridge session token 走 token exchange。
- 保持自动改写 URL 关闭，避免 CLI 覆盖已部署地址。
- 不要把学生优惠或其他 Shopify App 的代码与资源放进本目录。
- 不要把 Laravel 应用、Node 后端、Prisma 或本地数据库放回本目录，
  `npm run check:project` 会拦住这些残留。
