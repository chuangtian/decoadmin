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
| `local` | `deco-instagram-feed-local` | `https://wendy-interim-classic-segment.trycloudflare.com` | 已预置于 `shopify.app.local.toml` |
| `test` | `deco-instagram-feed-test` | `https://testadmin.decomkt.com` | 需 `config:link:test` 拉取 |
| `production` | `deco-instagram-feed` | `https://admin.decomkt.com` | 需 `config:link:production` 拉取 |

三套环境是 Dev Dashboard 里各自独立的 Shopify App，App ID、Secret、URL、数据和
发布动作都不可互换。测试与生产的 `client_id` 故意留空，避免克隆仓库后误把测试或
生产配置指向本地开发 App。

后端通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择环境。Client Secret
只放在未跟踪的环境变量里：`INSTAGRAM_FEED_<ENV>_CLIENT_SECRET`，或公共回退变量
`INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

## 常用命令

```bash
# 结构闸门 + 构建 local。不依赖 Shopify 登录，随时可跑。
npm run check

# Shopify 侧配置校验，需要已登录 CLI。
# 测试与生产的 App 创建并 link 之后才能跑对应的两条。
npm run check:config
npm run check:config:test
npm run check:config:production

# 本地开发（需要后端隧道已启动）
npm run dev

# 按环境构建
npm run build:local
npm run build:test
npm run build:production

# 首次为测试/生产拉取 client_id
npm run config:link:test
npm run config:link:production
```

发布需要显式指定环境，且必须先确认后端已就绪：

```bash
npm run deploy:local
npm run deploy:test
npm run deploy:production
```

## 修改本地隧道地址

Cloudflare 隧道地址会变。换地址时以下位置必须同步，且改完要重新 `deploy:local`
才生效（两套配置都设了 `automatically_update_urls_on_dev = false`，CLI 不会自动改）：

1. `shopify.app.toml` 与 `shopify.app.local.toml` 各 3 处：`application_url`、
   两条 `webhooks.subscriptions.uri`、`auth.redirect_urls`；
2. `scripts/validate-project.mjs` 里的 `configurations` 映射；
3. 根项目 `config/instagram_feed.php` 的 local `app_url`；
4. Meta 开发者后台的 OAuth redirect URI、Deauthorize 与 Data deletion 回调地址。

## 安全约定

- 不要把 Client Secret、Access Token、Meta 凭证或 R2 密钥写进 TOML、源码或文档。
- 保持 `embedded = true` 与 `use_legacy_install_flow = false`：安装由 Shopify 托管，
  会话通过 App Bridge session token 走 token exchange。
- 保持自动改写 URL 关闭，避免 CLI 覆盖已部署地址。
- 不要把学生优惠或其他 Shopify App 的代码与资源放进本目录。
- 不要把 Laravel 应用、Node 后端、Prisma 或本地数据库放回本目录，
  `npm run check:project` 会拦住这些残留。
