# Instagram 内容 Shopify App 对接契约

## 环境映射

| 环境 | Client ID | Handle | 应用域名 |
| --- | --- | --- | --- |
| `local` | 已废弃（隧道环境不再维护） | — | — |
| `test` | `d3446448682d2950aa75cea4a399d50f`（由 `INSTAGRAM_FEED_TEST_CLIENT_ID` 提供） | `deco-instagram-feed` | `https://testadmin.decomkt.com` |
| `production` | `d3446448682d2950aa75cea4a399d50f`（由 `INSTAGRAM_FEED_PRODUCTION_CLIENT_ID` 提供） | `deco-instagram-feed` | `https://admin.decomkt.com` |

运行环境通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择。对应 Client Secret 只放在未跟踪的环境变量中：`INSTAGRAM_FEED_<ENV>_CLIENT_SECRET`，也可使用当前环境公共回退变量 `INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

测试与生产目前是**同一个** Shopify App：一个 App 只有一份 `application_url` 和一组 webhook 地址，所以两个环境不能同时生效，发布哪一套配置就等于停用另一套。当前该 App 指向测试服，生产入口停用。切换步骤见 `shopify-apps/instagram-feed/README.md` 的「切换环境」。配置未补齐时后端一律返回 `INSTAGRAM_FEED_APP_NOT_CONFIGURED`（503），不会向 Shopify 发起任何调用。

三套 App 的固定授权集合均为：`read_products`。App 只需要解析关联商品的标题与 handle；写入自己的 app-data metafield 不需要额外 scope。后台 token exchange 后校验响应 `scope`，并通过 `currentAppInstallation.accessScopes` 再次核验安装权限；缺少必要权限时返回 `SHOPIFY_REQUIRED_SCOPES_MISSING`，不会继续建立会话。

本 App **不使用 App Proxy**：前台内容通过 app-data metafield 在服务端渲染，店面不回请 DecoAdmin。

## 安装与会话模型

安装由 **Shopify 托管**，三份 TOML 都不声明 `use_legacy_install_flow`。商家点安装链接后由 Shopify 弹权限授予页，装好后直接把应用打开在 `application_url`。

`[auth] redirect_urls` 仍然要声明（Shopify CLI 的 schema 要求），但它指向 App Home 入口 `/shopify-app/instagram-feed` 本身，而不是任何授权回调——后端已经没有回调可用。这与 `deco-personalization` 的做法一致。`scripts/validate-project.mjs` 会强制这一点，并拦住任何 `/oauth/callback` 引用。**后台没有任何人工授权入口**：DecoAdmin 侧原来的授权码链路（`InstagramFeedShopifyOAuthController`、`InstagramFeedOAuthService`、`/shopify-authorize`、`/shopify-verify`、`/shopify-app/instagram-feed/oauth/callback`）已全部移除。

会话的唯一建立途径是 App Bridge 的 session token 换 offline token（`ShopifyInstagramFeedAppService::bootstrap`）。`InstagramFeedEmbeddedSession::store()` 在每个内容管理请求上做两件事：

1. 用 session token 的 `dest` 精确匹配 `stores.shopify_domain` 解析店铺（该列全局唯一，因此唯一确定组织）；
2. 发现 `instagram_feed_installations` 记录不可用（首次打开、令牌被清空、环境切换后 `environment` 不匹配）时，自动补一次 token exchange。

因此商家不需要任何授权动作，**安装即用**；运维也不需要为某个店铺"重新授权"。

前置条件：该店铺必须已在 DecoAdmin 登记且 `shopify_domain` 完全一致，否则返回 `STORE_NOT_CONNECTED`（409）。另外 `app_installations.shopify_connection_id` 非空且带 `(shopify_connection_id, store_id)` 复合外键，所以登记安装记录要求店铺已连接 DecoAdmin 主 App。

## App Home：自托管 iframe 页面

```http
GET /shopify-app/instagram-feed?shop={shop}.myshopify.com&host=...&embedded=1&id_token=...
```

这是商家在 Shopify 后台看到的页面，走 Shopify 官方推荐的自托管 iframe 模型（UI 由 DecoAdmin 提供，`shopify-apps/instagram-feed/` 只保留 App 配置与扩展）。

这条路由**不挂 `auth`**（商家没有 DecoAdmin 账号），并且**不输出任何店铺数据**：请求到达时还没有可信身份，壳页面只渲染公开的 client id、接口前缀与环境名，加载 App Bridge 与前端包。真正的身份校验发生在后续每个 API 请求上。

`shopify.embedded-frame` 中间件按 `shop` 下发 `Content-Security-Policy: frame-ancestors https://{shop} https://admin.shopify.com`，并移除 `X-Frame-Options`（两者语义冲突会导致 iframe 白屏）。`shop` 参数格式不合法时退回 `frame-ancestors 'none'`。

前端位于 `resources/js/embedded/instagram-feed*`，是独立于后台主包的第二个 Vite 入口：不走 Inertia、不带 Cookie（`credentials: 'omit'`），每次请求现取一次 session token 作 `Authorization: Bearer`，收到 401 就换新令牌重试一次。

## Shopify App Home：内容管理接口

全部挂 `shopify.id-token:instagram_feed`，前缀 `/api/shopify-app/instagram-feed`。同步与转存打外部 API，限流比一般写操作更严。

| 方法 | 路径 | 说明 | 限流 |
| --- | --- | --- | --- |
| GET | `/overview` | 账号、转存统计、展示组列表、连接状态、能力集 | 60/min |
| GET | `/settings` | 本店铺应用配置（密钥不回显，只给 `*_configured` 与 `source`） | 60/min |
| PUT | `/settings/meta` | 保存本店铺 Meta 应用凭证 | 20/min |
| PUT | `/settings/r2` | 保存本店铺 R2 存储凭证 | 20/min |
| POST | `/account/authorize` | 取 Meta 授权链接（需新窗口打开） | 20/min |
| POST | `/account/select-page` | 多主页时确认要连接的账号 | 20/min |
| DELETE | `/account` | 断开 Meta 授权并清理转存产物 | 10/min |
| POST | `/sync` | 从 Instagram 拉取媒体 | 6/min |
| POST | `/mirror` | 推进 R2 转存 | 12/min |
| POST | `/media/{media}/retry-mirror` | 重试单条转存 | 30/min |
| GET | `/mirror-failures` | 转存失败日志（归纳原因 + 脱敏原始信息，最多 50 条） | 60/min |
| POST | `/mirror-failures/retry` | 把全部失败项改回 `pending` 重新排队 | 12/min |
| POST | `/storefront-sync` | 手动把当前内容推到店铺前台（兜底，正常不需要） | 12/min |
| GET | `/galleries/{gallery}` | 展示组详情（候选、组内、关联商品） | 60/min |
| POST | `/galleries` | 新建展示组 | 30/min |
| PUT | `/galleries/{gallery}` | 重命名 | 30/min |
| DELETE | `/galleries/{gallery}` | 删除 | 30/min |
| POST | `/galleries/{gallery}/items` | 加入成员 | 60/min |
| DELETE | `/galleries/{gallery}/items` | 移出成员 | 60/min |
| PUT | `/galleries/{gallery}/order` | 保存组内顺序 | 60/min |
| PUT | `/media/{media}/products` | 保存关联商品 | 60/min |

**没有「发布到前台」接口**：凡是会改变前台展示的写操作（同步、转存、重试、建组/改名/删组、加成员/移出/排序、关联商品）在成功后自动同步一次前台，失败原因作为消息后缀附在响应里。`/storefront-sync` 只是「前台没跟上」时的自助恢复入口。

约定：

- 成功统一为 `{"data": ...}`；业务失败统一为 `{"error": {"code", "message"}}`；入参校验失败沿用 Laravel 的 422 `{"message", "errors"}`。
- 写操作只回 `{"data": {"message": "..."}}`，不回视图数据，由前端按需重新拉取，避免每个写接口绑定某个页面。
- `{gallery}` 与 `{media}` 按 `uuid` 隐式绑定，绑定时不带店铺条件，因此控制器与服务层都会显式校验归属，跨店铺的 uuid 返回 404。
- 读侧数据由 `InstagramFeedPresenter` 拼装，与 DecoAdmin 后台页面共用同一份结构，避免两边分叉。
- 内嵌环境里没有 DecoAdmin 用户，因此**没有按人的 `instagram_feed.*` 授权**：能在 Shopify 后台打开应用的店铺员工即可操作该店铺内容与该店铺自己的应用配置。这是 Shopify App 的常规信任模型。审计记录 `user_id` 为空、`actor_type` 记 `shopify_app_session`、并附 `shop_domain`。
- 应用配置（Meta 应用凭证、R2 存储凭证）**按店铺存储**，所以商家改自己的凭证不会影响其它店铺。密钥列永不回显（只给 `*_configured` 布尔值），也读不到平台兜底值的明文。

## Meta 授权凭证

两条授权路线至少配一种，也可以都配（应用页面会同时显示两个连接入口）：

| 路线 | provider | 环境变量 |
| --- | --- | --- |
| Instagram 账号授权 | `instagram_login` | `INSTAGRAM_FEED_INSTAGRAM_APP_ID`、`INSTAGRAM_FEED_INSTAGRAM_APP_SECRET` |
| Facebook 主页授权 | `facebook_login` | `INSTAGRAM_FEED_FACEBOOK_APP_ID`、`INSTAGRAM_FEED_FACEBOOK_APP_SECRET` |

这是 Meta 后台里两组不同的凭证，不要混用。回调地址默认由当前环境应用域名推导，可用 `INSTAGRAM_FEED_INSTAGRAM_REDIRECT_URI` 与 `INSTAGRAM_FEED_FACEBOOK_REDIRECT_URI` 覆盖，且必须与 Meta 后台登记的地址完全一致。

App ID、App Secret 和 Facebook 登录配置 ID 由**商家自己**在 Shopify App 的「应用配置」页签维护，按店铺存储（见下节）。`.env` 里的这几项只作为没配过的店铺的兜底默认值。

同一个环境下所有店铺共用同一个回调地址，所以每个店铺的 Meta 应用都要把这个地址原样填进 OAuth 设置里。应用页面会把两条回调地址直接列出并提供复制按钮。

## Cloudflare R2

Instagram CDN 链接带签名会过期，同步后必须把视频与封面转存到 R2，对外用绑定在桶上的自定义域名给永久地址。相关变量：`INSTAGRAM_FEED_R2_ACCOUNT_ID`、`INSTAGRAM_FEED_R2_ACCESS_KEY_ID`、`INSTAGRAM_FEED_R2_SECRET_ACCESS_KEY`、`INSTAGRAM_FEED_R2_BUCKET`、`INSTAGRAM_FEED_R2_PUBLIC_BASE_URL`。

这五项同样由商家在「应用配置」页签按店铺维护，`.env` 只作兜底。五项必须全部填齐才算就绪。**换桶不会迁移旧素材**：`video_url` / `poster_url` 是转存当时写死的完整地址，仍指向旧桶，要重新转存才会搬过来。

R2 未配置时同步只拉取元数据、不转存，且未转存的内容不会发布到前台。对象 key 为 `{环境}/{店铺域名}/videos/{ig_media_id}.mp4` 与 `{环境}/{店铺域名}/posters/{ig_media_id}.jpg`，按环境与店铺隔离，重复转存是覆盖而不是堆积。

转存实现不把文件读进内存：下载走 HTTP 客户端 `sink` 落临时文件，上传走文件流，单文件上限由 `INSTAGRAM_FEED_MIRROR_MAX_OBJECT_BYTES` 控制（默认 200MB）。R2 请求使用手写 AWS SigV4 签名，不引入 aws-sdk。

## 应用配置：按店铺存储

Meta 应用凭证与 R2 存储凭证存在 `instagram_feed_store_settings`（`store_id` 唯一），三个密钥列走 Eloquent 的 `encrypted` cast，明文不落库。模型上标了 `#[Hidden]`，不会随序列化外泄。

为什么按店铺存：这些凭证由商家在内嵌页维护，而内嵌链路没有 DecoAdmin 用户、没有按人 RBAC。如果仍存平台级共享一份，任何一个店铺员工都能改掉影响全平台的密钥。按店铺隔离后，越权范围收敛回「只影响自己店铺」。

生效顺序：**店铺级有值 → `.env`**。留空表示回退，不是清空。

解析集中在 `InstagramFeed\InstagramFeedStoreCredentials`：

| 方法 | 作用 |
| --- | --- |
| `apply(Store)` | 把该店铺的生效凭证覆盖进 `config('instagram_feed.*')`。所有读凭证的动作之前都要先调一次 |
| `forFrontend(Store)` | 内嵌页表单数据。密钥回空字符串 + `*_configured` 布尔值；`source` 标明这一组是店铺自己配的（`store`）还是在用兜底默认（`platform`） |
| `update(section, store, values, actor, shopDomain)` | 写入。只写密钥留空表示保持原值；返回真正变化的字段名并写审计 |
| `allMetaAppSecrets()` | 所有店铺级 Meta 密钥，供 `signed_request` 验签逐个尝试 |

`R2Client` / `InstagramApiClient` / `FacebookApiClient` 都是**调用时才读 `config()`**，所以 `apply()` 覆盖 config 就够了，这三个类不需要任何改动。

`apply()` 是进程内的全局覆盖，有两条必须遵守的规则：

1. 同一进程里连续处理多个店铺（定时任务）时，**每个店铺都要重新 `apply()`**，否则会串用上一个店铺的凭证。所以 `apply()` 始终写入完整的一组值，缺失项显式回落到 baseline，不做增量覆盖。
2. `InstagramFeedStoreCredentials` 在容器里注册为**单例**（`AppServiceProvider::register()`）。它在首次 `apply()` 时快照当前 config 作为回退基线；每个店铺一个实例会把上一个店铺的覆盖值误当成基线。

已接入 `apply()` 的入口：

| 入口 | 时机 |
| --- | --- |
| `InstagramFeedEmbeddedSession::store()` | 解析出店铺并确保会话可用之后。一处覆盖所有内嵌接口 |
| `InstagramFeedController::index()` | 后台只读页渲染前（就绪徽标要按店铺判断） |
| `InstagramFeedMetaCallbackController::oauthCallback()` | `state` 还原出店铺之后、调 Graph API 换 token 之前 |
| `AdvanceInstagramFeedMirrors` | 每个店铺循环内。**不能在循环外做一次全局 R2 就绪判断就整体跳过** |
| `InstagramFeedWebhookService::handleUninstall()` | 删 R2 对象之前 |
| `InstagramAccountService::purgeByMetaUser()` | 循环内每个店铺（一个 Meta 用户可能授权了多个店铺，桶可能不同） |
| `MetaSignedRequestValidator::candidateSecrets()` | 不调 `apply()`，改为把所有店铺级密钥并入候选逐个验签 —— 合规回调里没有任何店铺线索 |

审计：配置改动记 `instagram_feed_store_settings_updated`，`metadata` 里有 `section`、`changed_keys`（**只记字段名，不记值**）、`actor_type`、`shop_domain`。

迁移路径：`2026_09_10_000100` 建表；`2026_09_10_000200` 把原来 `system_settings` 里 `instagram_meta` / `instagram_r2` 两个分组的值按店铺复制一份（范围是有 `instagram_accounts` 或 `instagram_feed_installations` 记录的店铺），然后删掉平台级那两个分组。`SystemSettingsService` 已不再认识这两个分组，也不再覆盖 `config('instagram_feed.*')`。解不开旧密文（换过 `APP_KEY`）时只记一条 warning 并跳过，商家在应用里重填一次即可。

## App Home：连接检查

```http
GET /api/shopify-app/instagram-feed/connection?shop={shop}.myshopify.com
Authorization: Bearer {Shopify OIDC ID Token}
Accept: application/json
```

服务端使用当前环境 Client Secret 校验 JWT `HS256` 签名，并校验：

- `aud` 等于当前环境 Client ID；
- `dest` 等于 `https://{shop}.myshopify.com`；
- `iss` 等于 `https://{shop}.myshopify.com/admin`；
- `exp`、`nbf`、`iat` 有效；
- 查询参数 `shop` 与 `dest` 完全一致。

成功响应：

```json
{
  "data": {
    "connected": true,
    "environment": "local",
    "store": {
      "id": 1,
      "name": "Macfox US"
    }
  }
}
```

无效 ID Token 返回 `401` 和 `X-Shopify-Retry-Invalid-Session-Request: 1`，错误码为 `INVALID_SHOPIFY_ID_TOKEN`。

## App Home：安装 bootstrap

```http
POST /api/shopify-app/instagram-feed/bootstrap?shop={shop}.myshopify.com
Authorization: Bearer {Shopify OIDC ID Token}
Accept: application/json
```

服务端验证 ID Token 后，将其交换为 **offline** Admin API token，查询 `currentAppInstallation.id`，并把两者加密保存到 `instagram_feed_installations`。

为什么必须存 offline token：app-data metafield 归属写入它的那个 App 的 AppInstallation，而 Theme App Extension 通过 `app.metafields` 只能读到自己所属 App 的值。因此发布前台数据必须用本 App 的身份，不能借用 DecoAdmin 主 App 的 `ShopifyConnection`；而 DecoAdmin 后台没有 App Bridge 会话，拿不到 online token。

成功响应：

```json
{
  "data": {
    "environment": "local",
    "store": {
      "id": 1,
      "name": "Macfox US",
      "shopify_domain": "macfox-us.myshopify.com"
    },
    "app_installation_id": "gid://shopify/AppInstallation/123",
    "granted_scopes": ["read_products"]
  }
}
```

这个端点由 App Home 前端在需要时显式调用；此外 `InstagramFeedEmbeddedSession::store()` 会在任何内容管理请求发现会话不可用时自动调用同一段逻辑，所以正常路径下商家不会碰到"会话缺失"。

从 DecoAdmin 后台侧发起发布（例如运维手动操作）时若会话尚未建立，仍会返回 `INSTAGRAM_FEED_APP_SESSION_MISSING`（409）——后台没有 App Bridge 会话，拿不到 session token，无法自行建立。此时正确做法是让商家在 Shopify 后台打开一次应用。

## Meta OAuth 回调

```http
GET /instagram-feed/oauth/instagram/callback?code=...&state=...
GET /instagram-feed/oauth/facebook/callback?code=...&state=...
```

这两个路由公开且没有 DecoAdmin 会话，靠一次性 `state` 还原店铺。`state` 复用 DecoAdmin 既有 `oauth_states` 表：明文只出现在授权链接里，库里只存 sha256，带 15 分钟过期与 `consumed_at` 一次性消费标记，因此除了防伪造还能防重放。`payload.purpose` 固定为 `instagram_feed_meta_oauth`，且校验 `payload.environment` 与当前环境一致。

授权在新窗口完成，回调返回一段极简 HTML：展示结果、向 `window.opener` 发送 `{ type: 'instagram-feed-auth', ok: boolean }`，成功后 1.5 秒自动关闭。应用页面收到消息后重新拉取账号数据。

换 token 用的是**发起授权那个店铺自己的** Meta 应用密钥，所以 `state` 还原出店铺之后必须先 `apply()` 该店铺的凭证再调 Graph API。

Token 生命周期：

- `instagram_login`：短期 token → 60 天长效 token，剩余有效期少于 `INSTAGRAM_FEED_REFRESH_WHEN_DAYS_LEFT` 天（默认 10）时自动续期；续期失败不阻断同步，继续用旧 token 试一次。
- `facebook_login`：短期用户 token → 60 天长效用户 token → 从 `/me/accounts` 取主页 token。主页 token 不过期。若授权者管理多个关联了 IG 的主页，账号进入 `needs_page_selection` 状态，商家回应用页面选定后才可用。

Meta 返回的错误正文可能带令牌片段，服务端只提取结构化 `error.message` 并截断到 200 字符；面向公网的回调页对过长或为空的消息统一替换为通用提示。

## Meta 合规回调

```http
POST /instagram-feed/meta/deauthorize
POST /instagram-feed/meta/data-deletion
GET  /instagram-feed/meta/data-deletion?code={confirmation_code}
```

两个 POST 回调都用 `signed_request` 验签（`base64url(签名).base64url(载荷)`，HMAC-SHA256）。两条授权路线密钥不同，逐个尝试；全部不匹配时：

- `deauthorize` 返回 `200` 但不做任何处理，不透露账号是否存在；
- `data-deletion` 返回 `400` 和 `{"error": "invalid signed_request"}`。

验签通过后按 Meta 用户 ID 找到对应店铺，删除授权记录，并连带清理 R2 对象与本地媒体记录。清理顺序是先删对象再删记录：对象 key 只存在数据库里，先删记录就再也找不到这些对象；反过来中途失败还能重跑接着删。R2 删除失败只记日志、不阻断数据库清理，失败的 key 需要人工清。

`data-deletion` 返回规范要求的结构：

```json
{
  "url": "https://admin.decomkt.com/instagram-feed/meta/data-deletion?code=0123456789abcdef",
  "confirmation_code": "0123456789abcdef"
}
```

确认码由 `sha256(user_id + 时间戳)` 截断而来，不含任何用户数据。

## Shopify Webhook

```http
POST /api/shopify-app/instagram-feed/webhooks
```

使用当前环境 Client Secret 校验 Shopify HMAC，与 DecoAdmin 主 App 的 webhook 通道完全隔离。仅支持 `app/uninstalled` 与 `app/scopes_update`。店铺仅取自 `X-Shopify-Shop-Domain`，不信任请求体中的 `shop`。按事件 UUID 与 payload hash 幂等，冲突返回 `409`；原始 payload 加密保存。载荷上限 10MB。

订阅由 **TOML 声明**、Shopify 统一管理与重投：三份配置里各有两条 `[[webhooks.subscriptions]]` 指向本环境的 `/api/shopify-app/instagram-feed/webhooks`。托管安装才允许 app 级订阅，所以原来按店铺注册的 `InstagramFeedWebhookSubscriptionService` 已删除。这样改的好处是「装了但从未打开过应用」的店铺卸载时同样能收到通知——按店铺注册做不到这一点，因为注册发生在授权成功之后。

要扩展监听范围时，`InstagramFeedWebhookService::ALLOWED_TOPICS` 与三份 TOML 的订阅声明**必须成对修改**，否则新 topic 会被 `UNSUPPORTED_WEBHOOK_TOPIC` 拒掉。`scripts/validate-project.mjs` 会校验两条生命周期订阅都在、且地址落在本环境 origin 上。

`app/uninstalled` 会清理该店铺的授权、展示组、媒体记录、R2 对象和 App 会话。媒体多时可能超过 webhook 响应预算被 Shopify 判超时后重投；清理是可续的，重投能接着上次进度继续删，全删完后再重投只是空跑一次。

## 前台数据交付

发布把内容写进 AppInstallation 的 app-data metafield：

```json
{
  "namespace": "instagram_videos",
  "key": "feed",
  "type": "json"
}
```

值结构：

```json
{
  "updated_at": "2026-08-25T10:00:00+00:00",
  "username": "macfoxbike",
  "profile_url": "https://www.instagram.com/macfoxbike/",
  "avatar_url": "https://...",
  "galleries": [
    {
      "handle": "a1b2c3d4",
      "name": "首页轮播",
      "items": [
        {
          "id": "17900000000000000",
          "media_type": "VIDEO",
          "permalink": "https://www.instagram.com/reel/...",
          "caption": "文案",
          "video_url": "https://cdn.example.com/local/macfox-us.myshopify.com/videos/179....mp4",
          "poster_url": "https://cdn.example.com/local/macfox-us.myshopify.com/posters/179....jpg",
          "width": null,
          "height": null,
          "duration_ms": null,
          "posted_at": "2026-08-20T02:00:00+00:00",
          "products": [{ "handle": "macfox-x2", "title": "MacFox X2" }]
        }
      ]
    }
  ],
  "items": []
}
```

约定：

- 每组只包含 `mirror_status = ready` 的媒体，未转存完的还挂在会过期的 IG CDN 上，不会发布；
- 每组条数上限 50，文案截断到 300 字符并去掉尖括号，避免内联进 JSON script 标签时出现 `</script>` 截断标签；
- 顶层 `items` 是旧版兼容字段，指向第一个组，供还没升级到按组选择的主题使用；
- 已删除的商品解析不到，会被自动过滤，不会把死链发到前台；
- 发布前校验序列化后不超过 Shopify 单个 metafield 64KB 上限，超限返回 `FEED_PAYLOAD_TOO_LARGE`；
- 写入后回读校验 `key`，不一致返回 `FEED_PUBLISH_FAILED`。

Theme App Extension 通过 `app.metafields.instagram_videos.feed.value` 读取，商家在区块设置 `gallery_handle` 里填写展示组标识来指定展示哪一组；留空或找不到时回退到第一个组，并只在主题编辑器里提示。

## 同步与转存

同步流程：拉取 Instagram 媒体 → 差量落库 → 推进 R2 转存。

- 分页按游标翻到底，游标重复即中止，防止死循环；上限由 `INSTAGRAM_FEED_FETCH_ALL_ITEMS` 控制（默认 2000），单页 50 条；
- Instagram Login 首次请求带 `like_count` / `comments_count`，部分账号取不到时降级为精简字段重试一次；
- 已转存成功且元数据未变的条目直接跳过。IG 的媒体地址每次同步都带新签名，若逐条比对会让每次同步退化成上千次无意义写入；
- 转存状态机 `pending → processing → ready / failed`。`processing` 是占位状态，进程中断会留在这里，超过 `INSTAGRAM_FEED_MIRROR_STALE_SECONDS`（默认 300）后下一轮重新捞出重试；
- 视频用 IG 缩略图当封面，图片本身就是封面。视频已转存成功但封面失败时不整条失败，前台 `<video>` 没有 poster 也能播；
- 单次转存条数由 `INSTAGRAM_FEED_MIRROR_BATCH_SIZE`（默认 10）控制，串行处理。

调度兜底：`instagram-feed:advance-mirrors` 每 10 分钟推进队列并顺带续期快到期的长效 token，商家不必反复点按钮。

## DecoAdmin 后台：维护职责

内容管理与应用配置都已搬到 Shopify App Home，后台的定位收敛为**只读运维视图**：

| 职责 | 位置 | 说明 |
| --- | --- | --- |
| 连接状态查看 | 应用中心 → Instagram Feed | 展示 `instagram_feed_installations` 的状态、首次连接、最近校验/检测、最近失败原因 |
| 转存素材进度 | 同上 | 已同步 / 已转存 / 待转存 / 失败 / 展示组数量，外加最近 20 条转存失败原因（重试入口在 Shopify App 里） |
| 展示区查看 | 同上 | 展示组名称、条目数、组标识、缩略图预览 |
| 连接探活 | `InstagramFeedConnectionHealthService::check()` | 跑一次 `currentAppInstallation`，401/403 → `invalid`，其它失败 → `warning`，查不到安装记录 → `disconnected`，成功 → `connected`。状态口径与主 App 的 `ShopifyConnectionHealthService` 一致 |
| 转存队列兜底 | `instagram-feed:advance-mirrors` | 每 10 分钟推进转存并续期快到期的长效 token。凭证按店铺加载，每个店铺单独判断 R2 是否就绪 |
| 安装记录回填 | `instagram-feed:reconcile-installations` | 一次性命令，把已建立会话但缺 `app_installations` 记录的店铺补进应用中心。支持 `--shop=` 与 `--dry-run` |
| 事件溯源 | `webhook_events` | 该店铺本 App 的全部 webhook 事件，payload 加密保存 |
| 操作溯源 | `audit_logs` | 来自 Shopify App 的操作 `user_id` 为空、`actor_type = shopify_app_session`，按 `shop_domain` 追溯。配置改动记 `instagram_feed_store_settings_updated`，只记字段名不记值 |

排障顺序建议：先看后台连接状态卡片的 `last_error`，再看转存失败原因，然后看 `webhook_events` 是否收到卸载/权限变更事件，最后看 `audit_logs` 里该店铺的操作序列。

后台只有一条 Instagram Feed 路由，**没有任何写路由**：

```http
GET /organizations/{organization}/stores/{store}/instagram-feed
```

控制器内除路由中间件外再校验一次组织归属、店铺可访问性与 `instagram_feed.view`（双保险）。

为什么不在后台保留写入口：同一份数据两个写入口意味着两套鉴权边界要同步维护，而内嵌链路（按 shop domain 判定店铺）已经覆盖商家的全部需求。运维需要介入时走命令行，不走 HTTP。

权限种子只剩一条：

- `instagram_feed.view`

`instagram_feed.connect`、`instagram_feed.sync`、`instagram_feed.gallery.manage`、`instagram_feed.publish` 已随后台写入口一起退役，由迁移 `2026_09_10_000200_move_instagram_feed_credentials_to_stores` 软删除。`PermissionSeeder` 按 slug 增量 upsert，不再列出即保持软删除；该迁移的 `down()` 会恢复它们。

## 结构化错误

```json
{
  "error": {
    "code": "INSTAGRAM_ACCOUNT_NOT_CONNECTED",
    "message": "还没有连接 Instagram 账号。"
  }
}
```

常见错误码：`INSTAGRAM_FEED_APP_NOT_CONFIGURED`、`INSTAGRAM_FEED_APP_REGISTRY_CONFLICT`、`INVALID_SHOPIFY_ID_TOKEN`、`SHOPIFY_REQUIRED_SCOPES_MISSING`、`SHOPIFY_APP_NOT_INSTALLED`、`SHOPIFY_TOKEN_EXCHANGE_FAILED`、`SHOPIFY_TOKEN_EXCHANGE_TIMEOUT`、`INSTAGRAM_FEED_APP_SESSION_MISSING`、`SHOPIFY_APP_SESSION_INVALID`、`SHOPIFY_ADMIN_API_FAILED`、`SHOPIFY_ADMIN_API_TIMEOUT`、`STORE_NOT_CONNECTED`、`STORE_ACCESS_DENIED`、`INVALID_OAUTH_STATE`、`OAUTH_PROVIDER_MISMATCH`、`PROVIDER_NOT_CONFIGURED`、`INSTAGRAM_ACCOUNT_NOT_CONNECTED`、`INSTAGRAM_ACCOUNT_NOT_READY`、`FACEBOOK_NO_INSTAGRAM_ACCOUNT`、`FACEBOOK_PAGE_NOT_FOUND`、`FACEBOOK_AUTHORIZATION_EXPIRED`、`GALLERY_NOT_FOUND`、`GALLERY_NAME_REQUIRED`、`GALLERY_NAME_TOO_LONG`、`GALLERY_REORDER_EMPTY`、`TOO_MANY_GALLERY_ITEMS`、`INSTAGRAM_MEDIA_NOT_FOUND`、`INVALID_PRODUCT_GID`、`TOO_MANY_LINKED_PRODUCTS`、`R2_NOT_CONFIGURED`、`FEED_PAYLOAD_TOO_LARGE`、`FEED_PUBLISH_FAILED`、`INVALID_WEBHOOK_HMAC`、`INVALID_WEBHOOK_HEADERS`、`UNSUPPORTED_WEBHOOK_TOPIC`、`WEBHOOK_ID_CONFLICT`、`WEBHOOK_PAYLOAD_TOO_LARGE`、`INSTAGRAM_FEED_ACTION_FAILED`。

`SHOPIFY_ADMIN_API_FAILED` 的文案会带上 Shopify 的真实 HTTP 状态与 GraphQL `errors[].message`，并对 `shpat_` / `shpca_` / `shppa_` / `shpss_` 前缀的令牌做脱敏后截断到 200 字符。同一条失败也会写入 `instagram_feed_installations.last_error`，后台连接状态卡片直接可见。

## 数据库迁移

`database/migrations/2026_08_25_030000_create_instagram_feed_tables.php` 创建：

- `instagram_accounts`：每店铺一条授权记录。`access_token_encrypted`、`fb_user_token_encrypted` 走 Eloquent encrypted cast。
- `instagram_media`：同步下来的媒体与转存进度。唯一键 `[store_id, ig_media_id]`。
- `instagram_galleries`：展示组。唯一键 `[store_id, handle]`，`handle` 是随机短哈希而非组名派生，改名不影响主题引用。
- `instagram_feed_installations`：本 App 的店铺会话，保存 AppInstallation GID 与加密的 offline token。
- `instagram_gallery_items`：组内成员与组内顺序。唯一键 `[gallery_id, media_id]`。

`database/migrations/2026_09_07_000100_add_connection_state_to_instagram_feed_installations_table.php` 补充连接状态字段：`status`（`connected` / `warning` / `invalid` / `disconnected`）、`installed_by`、`uninstalled_at`、`last_api_check`、`last_error`、`last_error_at`。字段集刻意对齐 `shopify_connections`，这样两个 App 的连接健康度可以用同一套判读口径。

原 Prisma schema 中的 `InstagramMedia.enabled` / `position` 是迁移期遗留字段（编排已全部走 GalleryItem），未迁移；`Session` 表由 Laravel 自身会话机制取代。
