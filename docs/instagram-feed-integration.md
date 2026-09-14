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
| GET | `/galleries/{gallery}` | 展示组详情（候选、组内、关联商品）。可选 `filter` / `search` / `from` / `to` | 60/min |
| POST | `/galleries` | 新建展示组 | 30/min |
| PUT | `/galleries/{gallery}` | 重命名 | 30/min |
| DELETE | `/galleries/{gallery}` | 删除 | 30/min |
| POST | `/galleries/{gallery}/items` | 加入成员 | 60/min |
| DELETE | `/galleries/{gallery}/items` | 移出成员 | 60/min |
| PUT | `/galleries/{gallery}/order` | 保存组内顺序 | 60/min |
| PUT | `/media/{media}/products` | 保存关联商品（空数组表示清除关联） | 60/min |

**没有「发布到前台」接口**：凡是会改变前台展示的写操作（同步、转存、重试、建组/改名/删组、加成员/移出/排序、关联商品）在成功后自动同步一次前台，失败原因作为消息后缀附在响应里。`/storefront-sync` 只是「前台没跟上」时的自助恢复入口。

**商品 GID 从哪来**：前端不让商家手填，走 App Bridge 的 `shopify.resourcePicker({ type: 'product' })`（封装在 `api.ts` 的 `pickProducts()`）。选择器跑在 Shopify 后台里，搜索、分页与可见性都由 Shopify 负责，只需要 `read_products`。打开时用 `selectionIds` 预选当前已关联的商品，`filter: { variants: false }` 阻止下钻到变体（我们只存商品级 GID）。商家直接关掉选择器时 App Bridge 返回 `undefined`（不是空数组），前端据此区分「取消」与「清空关联」。

**候选怎么找**：媒体库上千条，所以搜索与筛选全在服务端做，前端只负责传参。`/galleries/{gallery}` 接受四个可选参数：

| 参数 | 说明 |
| --- | --- |
| `filter` | `all`（默认）/ `VIDEO` / `IMAGE`。`IMAGE` 连带算上 `CAROUSEL_ALBUM` |
| `search` | 按文案模糊匹配，最长 100 字符。`%` `_` `\` 会被转义，不会被当成通配符 |
| `from` / `to` | 发布日期区间，`YYYY-MM-DD`，含当天（`from` 取 00:00、`to` 取 23:59:59）。`to` 必须不早于 `from` |

候选恒按 `posted_at` 倒序（最新在前），一次最多返回 `candidateLimit`（200）条。响应里回传 `search` / `from` / `to` 供前端回填输入框，另给 `matchedCount`（符合条件的总数）—— 大于返回条数就说明被截断了，前端据此提示商家继续收窄。组内成员一侧**不受这些参数影响**：拖拽排序要按全量重写 `position`，被筛掉会串号。

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

Instagram CDN 链接带签名会过期，同步后必须把**封面图**转存到 R2，对外用绑定在桶上的自定义域名给永久地址。相关变量：`INSTAGRAM_FEED_R2_ACCOUNT_ID`、`INSTAGRAM_FEED_R2_ACCESS_KEY_ID`、`INSTAGRAM_FEED_R2_SECRET_ACCESS_KEY`、`INSTAGRAM_FEED_R2_BUCKET`、`INSTAGRAM_FEED_R2_PUBLIC_BASE_URL`。

这五项同样由商家在「应用配置」页签按店铺维护，`.env` 只作兜底。五项必须全部填齐才算就绪。**换桶不会迁移旧素材**：`poster_url` 是转存当时写死的完整地址，仍指向旧桶，要重新转存才会搬过来。

R2 未配置时同步只拉取元数据、不转存，且未转存的内容不会发布到前台。对象 key 为 `{环境}/{店铺域名}/posters/{ig_media_id}.jpg`，按环境与店铺隔离，重复转存是覆盖而不是堆积。

转存实现不把文件读进内存：下载走 HTTP 客户端 `sink` 落临时文件，上传走文件流，单文件上限由 `INSTAGRAM_FEED_MIRROR_MAX_OBJECT_BYTES` 控制（默认 200MB）。R2 请求使用手写 AWS SigV4 签名，不引入 aws-sdk。

签名踩过的坑（改 `R2Client::signedRequest()` 前先读）：`Content-Type` 必须参与签名（在 `SignedHeaders` 里），但**不能同时**出现在 `withHeaders()` 和 `withBody()` 里。Laravel 的 `withHeaders()` 用 `array_merge_recursive` 合并，`'content-type'` 与 `'Content-Type'` 会各留一份，Guzzle 合并同名头后实际发出 `video/mp4, video/mp4`，与签名用的单值对不上，R2 一律回 403 `SignatureDoesNotMatch`。GET / DELETE 没有 body 也没有 Content-Type，所以只有上传会踩到 —— 现象极像「密钥填错了」，排查时容易被带偏。判别方法：用同一份凭证发一个最小化签名 GET（例如 ListObjectsV2），能过就说明凭证没问题，问题在上传路径。

## 不转存视频文件

Instagram 出于下载与版权保护，会对部分 Reels **静默省略 `media_url` 字段** —— HTTP 200、没有报错、字段整个不存在（不是 `null`），加 `debug=all` 也没有任何说明。触发条件是「使用了平台授权音乐」或「该 Reel 关闭了允许下载」，两者 API 都不暴露。实测某店铺 553 条 REELS 里有 65 条（约 12%）如此，散落在 2023-08 到 2026-01 整个区间，与发布时间无关，靠重试永远拿不到。

`thumbnail_url` 对所有视频都稳定返回，所以：

- 转存只搬封面图（`InstagramMirrorService::mirrorOne()`）；
- 播放交给点击封面后弹窗里的 **Instagram 官方 embed**（`{permalink}/embed/captioned`，见 `InstagramMedia::embedUrl()`）。embed 不需要令牌、不需要 `oembed_read` 审核，Instagram 也不下发 `X-Frame-Options` / `frame-ancestors`，可直接 iframe；视频与轮播都由 Instagram 自己渲染，不涉及版权问题。

顺带的好处：省掉大量视频存储与带宽，转存速度快了一个量级，且不再有「视频拿不到」这一类失败。

历史数据用 `instagram-feed:purge-mirrored-videos` 收尾（`--store=` / `--dry-run` / `--chunk=`）：删掉 R2 里遗留的视频对象并清空 `video_key` / `video_url`，同时把「`ready` 但没有封面」的条目重置为 `pending`（旧逻辑允许视频成功、封面失败，这类条目在纯图片网格里会是空白）。`mirrorOne()` 也会在重新转存单条时顺手删掉它遗留的视频对象。

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
          "embed_url": "https://www.instagram.com/reel/.../embed/captioned",
          "caption": "文案",
          "poster_url": "https://cdn.example.com/local/macfox-us.myshopify.com/posters/179....jpg",
          "width": null,
          "height": null,
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
- 只有 `poster_url`，没有 `video_url` —— 视频文件不转存，播放走 `embed_url`（见「不转存视频文件」）；
- 每组条数上限 50，文案截断到 300 字符并去掉尖括号，避免内联进 JSON script 标签时出现 `</script>` 截断标签；
- 顶层 `items` 是旧版兼容字段，指向第一个组，供还没升级到按组选择的主题使用；
- 已删除的商品解析不到，会被自动过滤，不会把死链发到前台；
- 发布前校验序列化后不超过 Shopify 单个 metafield 64KB 上限，超限返回 `FEED_PAYLOAD_TOO_LARGE`；
- 写入后回读校验 `key`，不一致返回 `FEED_PUBLISH_FAILED`。

Theme App Extension 通过 `app.metafields.instagram_videos.feed.value` 读取。

### 展示组怎么指定

**目标形态是主题编辑器里的一个选择器，直接列出商家自己的组名，不用打字。** 唯一能做到这件事的是 Shopify 的 `metaobject` 设置类型。

区块设置的 schema 是构建期静态 JSON，一个 app 版本服务所有店铺，所以 `select` 的 options 里放不进某个店铺的组名（[动态数据源不支持 select/radio](https://stackoverflow.com/questions/75565836/how-to-use-shopify-metafields-in-the-theme-app-block-dynamic-source-settings)）。走过两条弯路，都记在这里免得再来一遍：

- **序号 select（`Gallery 1`–`Gallery 10`）**：商家在设置面板里根本看不出 6 号是哪个组，选到越界的号只渲染空白，比手填更难用，删组还会让后面所有序号平移。
- **文本框填组名**：能用，但每加一个区块都要回应用里核对名字再手打一遍。

[官方 input settings 文档](https://shopify.dev/docs/storefronts/themes/architecture/settings/input-settings)明确支持 app 在 app block 里用自己的 app-owned metaobject definition 配 `metaobject` 设置，给出的范式就是「建 definition → app 写条目 → 区块用 metaobject 设置 → Liquid 读选中值」。

实现落在 `InstagramGalleryDirectory`：

| 项 | 值 | 为什么 |
| --- | --- | --- |
| definition type | `$app:instagram_gallery` | `$app:` 由 Shopify 解析成 `app--<app-id>--instagram_gallery`，本 App 独占，商家改不了结构。**不要自己拼 app id** |
| `displayNameKey` | `gallery_name` | 选择器按它显示条目。指错就等于让商家看随机 handle，回到手抄标识那个老问题 |
| 字段 | `gallery_name`、`gallery_handle` | 匹配用字段刻意不叫 `handle`：metaobject 在 Liquid 里本身就有内置的 `system.handle`，同名容易读错 |
| `access.storefront` | `PUBLIC_READ` | 少了它主题渲染时读不到条目 |
| capabilities | 不启用 `publishable` | 启用会引入 DRAFT/ACTIVE 状态，条目还得额外发布一次 |
| 条目 handle | 展示组的 `handle` | 店铺内已唯一，主题里能直接反查回同一个组，不用再存映射 |

**区块 schema 里的 `metaobject_type` 必须写完整解析后的类型，不能用 `$app:` 简写。** `$app:instagram_gallery` 在 Liquid 与 Admin API 里都能用，但区块 schema 的服务端校验直接拒掉它：

```
Version couldn't be created.
  • bundle: [blocks/instagram_videos.liquid] Invalid tag 'schema':
    settings: with id="gallery_ref" metaobject_type is invalid
```

所以那里硬编码成 `app--412885549057--instagram_gallery`（中间是 Shopify 分配的 **app id**，不是 `client_id`）。这是[社区反复提到的痛点](https://community.shopify.dev/t/issue-with-dynamic-app-id-in-theme-app-block-schema-settings/12010)，目前没有官方的动态写法。

硬编码就有过期风险：现在测试与生产共用同一个 Shopify App，一旦按 README 的「让测试与生产真正并行」拆成两个 App，app id 会变，选择器就会在主题编辑器里变成错误状态。`validate-project.mjs` 为此加了一条闸门，同时校验类型名与 `config('instagram_feed.metaobject.type')` 同源。

**另外两个前提缺一不可，否则主题编辑器同样把那个设置显示成错误**：definition 已存在于店铺上，且对 storefront 可读。社区里那些「app-owned metaobject 在 Liquid 里读不到」的案例大概率就是漏了后者。

由此得出**不可颠倒的上线顺序**（`shopify app deploy` 会把配置与扩展一起发，所以必须分两次）：

1. 部署后端（含 `InstagramGalleryDirectory` 与新 scope 声明）；
2. `shopify app deploy` 发布 scope 变更，此时扩展仍是旧版本设置；
3. `php artisan instagram-feed:sync-gallery-directory` 建 definition、补齐历史展示组；
4. 用 GraphQL 查证 `metaobjectDefinitionByType`：`access.storefront` 是 `PUBLIC_READ`、`displayNameKey` 指向组名字段、条目 handle 与库里的展示组一一对应；
5. 这一步通过之后，才发布带 `metaobject` 设置的扩展。

第 3 步实测时**不需要等商家手动重新授权**：Shopify 托管安装在 scope 变更发布后就把新权限给了 offline token，同步直接成功。`instagram_feed_installations.granted_scopes` 里仍是旧快照，那只是上一次 `bootstrap()` 的记录，下次打开应用才刷新 —— **别用它判断权限是否到位**，要看实际 API 调用结果。

**`write_metaobjects` 只进 `optional_scopes`，绝不能进 `required_scopes`。** `assertRequiredScopes()` 是在 `ShopifyInstagramFeedAppService::bootstrap()` 里跑的，而 bootstrap 每次建立内嵌会话都会走 —— 放进 `required_scopes` 会让尚未重新授权的店铺连应用都打不开。选择器只是主题编辑器里的便利，缺权限时正确行为是选项不更新，而不是让同步、转存、前台展示全部停摆。`shopify.app.*.toml` 的 `scopes` 必须等于两个列表的并集，这条由 `validate-project.mjs` 的 `expectedScopes` 把关（注意它是硬编码字符串，改 config 时要手动同步）。

同步时机：只有**增删改展示组**会改变选项集合，所以 `write()` 的 `syncDirectory` 只在 `storeGallery` / `updateGallery` / `destroyGallery` 与手动同步按钮上打开；往组里加/移内容不碰 Shopify。`sync()` 是全量对齐而非增量 —— 条目可能被人在 Shopify 后台手工删掉，也可能因上次中途失败而残留，每次按当前展示组重建一遍比维护增量状态可靠。失败一律不抛（`syncQuietly`），因为主动作已经生效，不能让商家以为组没改成。

`galleries` 的顺序由 `InstagramFeedPublisher` 按 `position, created_at` 保证稳定。顶层 `items` 仍是旧版兼容字段，等价于第一个组，`galleries` 为空时兜底。

前台渲染：**只有轮播一种布局**（`layout` / `full_width` / `max_width` / `gap` 四个设置已删除）。每个条目就是一张封面图（`<img>`，没有 `<video>`）。`show_posted_at` 控制是否在封面下方显示发布日期。

容器尺寸写死在 CSS 里，不再作为区块设置暴露：

| 断点 | width | max-width | padding |
| --- | --- | --- | --- |
| 桌面 | 90% | 1400px | 上下 30px，左右 0 |
| ≤749px | 100% | — | 上下 30px，左右 15px |

上下 30px 两端通用；左右内边距只在移动端出现 —— 桌面是 90% 宽度居中，两侧本来就有留白，再加左右 padding 会双重缩进。`.igv` 上的 `box-sizing: border-box` 保证 padding 算在声明宽度以内，所以移动端 `100%` 不会溢出。条目宽度的 `calc` 以 `.igv__list` 的内容区为基准，padding 变化后列宽自动跟着对。间距固定 12px，由 CSS 变量 `--igv-gap` 提供。

桌面端箭头用 `translate(-40%, -50%)` 会伸出轨道边缘约 16px，落在 `.igv` 的左右 padding 之外。90% 宽度居中留出的空白足够容纳，不会被视口裁掉；如果外层主题 section 加了 `overflow: hidden`，箭头才会被切。

**整个扩展是全英文的**（schema 的 name/label/info/options、`aria-label`、空态与 hint 文案、弹窗按钮、JS 里生成的文案，连注释也是英文）：它渲染在商家店面和主题编辑器里，出现中文就是 bug。弹窗里的日期用 `toLocaleDateString("en-US", …)` 锁定英文月份，不跟随访客语言。

**点击行为**由区块设置 `click_action` 二选一，两种都在服务端渲染成对应元素，禁用 JS 也能用：

| 取值 | 渲染成 | 行为 |
| --- | --- | --- |
| `modal`（默认） | `<button data-igv-open>` | 打开弹窗，把 `embed_url` 塞进 iframe。访客不离开店铺 |
| `link` | `<a target="_blank">` | 在新标签打开 `permalink` |

选 `modal` 时才会输出那段 `data-igv-json`（弹窗要用的数据），选 `link` 时连 JSON 都不渲染。JS 侧靠根元素的 `data-click-action` 判断是否接管点击。**关闭弹窗必须清掉 iframe 的 `src`** —— 不清的话 Instagram 会在后台继续播，有声音的 Reels 尤其明显。

**弹窗排版**：桌面端左右两栏 —— **左边是完整的 Instagram 帖子**（`{permalink}/embed/captioned`，含头像、用户名、媒体、文案），**右边只有关联商品和「View on Instagram」**。移动端（≤749px）改成上下叠。三个控制按钮（关闭 / 上一条 / 下一条）挂在遮罩 `.igv-lightbox` 上而不是 `.igv-lightbox__dialog` 上，`dialog` 宽到 `min(1040px, 100%)` 时按钮才不会被挤出屏幕。

右栏刻意不放文案和发布日期：**embed 自己已经渲染了这两样，两边都放就是重复**。曾经试过左栏用无文案的 `/embed/`、右栏放我们自己的文案，实测 `/embed/` 同样会渲染文案，结果两边各显示一份，而且左栏还被裁掉半句。

**embed 必须带 `?locale=en_US`。** 不加时它跟随访客浏览器的 `Accept-Language`，中文浏览器下会渲染出自己的中文界面（「查看个人主页」「粉丝」「已验证」）。实测：

| 请求 | HTML 里的中文字符数 |
| --- | --- |
| `Accept-Language: zh-CN`，无参数 | 39（`已验证粉丝查看个人主页…`） |
| `Accept-Language: zh-CN` + `?locale=en_US` | 0 |
| `Accept-Language: en-US`，无参数 | 0 |

这是唯一能控制 embed 语言的手段 —— 那段界面是 Instagram 渲染的，我们改不了 DOM。

**高度由 embed 自己报，而且不能给它设上限。** iframe 跨域读不到内容高度，Instagram 会 `postMessage` 一个 `{type:"MEASURE",details:{height}}`，JS 监听后写成 `.igv-lightbox__frame` 的行内 `height`。**校验 `event.origin === "https://www.instagram.com"` 用全等而不是 `indexOf`**，否则 `evil-instagram.com.attacker.test` 也能匹配上。消息没来时退回 CSS 里的 `height: 700px`。

**`.igv-lightbox__frame` 上任何 `max-height` 都等于裁掉帖子。** Instagram 的 embed 不滚动自己的 body —— 它报出高度，然后假定宿主会把 iframe 撑到那个高度。所以截断 iframe 高度不会换来内部滚动条，只会静默切掉下半篇。之前 `max-height: 92vh` 就是内容被裁的原因，去掉 `scrolling="no"` 也救不回来。

现在的做法是：frame 高度完全由 MEASURE 决定，**改由遮罩 `.igv-lightbox` 用 `overflow-y: auto` 承担超高的情况**。`.igv-lightbox__frame` 同样不能加 `overflow: hidden`，圆角由 `.igv-lightbox__embed` 自己的 `border-radius` 负责。

遮罩的滚动条是隐藏的（`scrollbar-width: none` + `-ms-overflow-style: none` + `::-webkit-scrollbar { display: none }`），滚动能力保留 —— 与 `.igv__list` 隐藏轨道滚动条用的是同一套写法。

遮罩用 `align-items: flex-start` 配 `.igv-lightbox__dialog { margin: auto }`，**不用 `align-items: center`**：居中一个溢出容器的 flex item 会让顶部那段溢出无法滚到，等于从另一头把帖子裁了。`margin: auto` 在内容不超高时照样居中。

弹窗面板是**浅色**的：`.igv-lightbox__dialog` 自己白底圆角，两栏都在里面，文字 `#1a1a1a`，商品卡片 `#f4f4f5`。两栏 `align-items: flex-start` 顶部对齐，右栏不再相对左边那张长图垂直居中。

三个控制按钮改成 `position: fixed`（原来是 `absolute`）：遮罩现在会滚动，绝对定位的关闭按钮在长帖子上会滚出视野。它们用深色底加白色字形，因为窄视口下白色面板几乎顶到边缘、正好垫在按钮下面，纯白按钮会看不见。

加载态是纯 CSS 转圈（`.igv-lightbox__spinner` + `@keyframes igv-spin`），没有文字，也就没有需要翻译的字符串。

**关联商品出现在两个地方，数据同源、样式不同：**

| 位置 | 类名 | 内容 | 条数 |
| --- | --- | --- | --- |
| 轮播封面底部（叠在图上） | `.igv__product` | 商品图 + 标题（单行截断）+ 价格 + 划线原价 | 只显示第一个 |
| 弹窗右栏 | `.igv-lightbox__product` | 商品图 + 标题（两行截断）+ 价格 + `Save …` 徽章 | 全部 |

封面上只显示第一个商品：卡片本来就窄，而弹窗里已经能看全。**`.igv__product` 的 `z-index` 必须压过 `.igv__play`** —— 后者是铺满整张封面的点击热区，不压过它商品链接就永远点不到。也因此 `.igv__play` 的图标挪到了封面右上角，那个位置原先正是它占着的。

两处都是 `<a>`，所以和 `.igv__play` 一样需要 `!important` 钉死背景与文字色，否则主题的链接 hover 规则会把卡片刷成主题色。它们与 `.igv__play` 是兄弟节点而非嵌套，没有嵌套 `<a>` 的问题。

弹窗右栏**整列在帖子没有关联商品时隐藏**（`.igv-lightbox__meta[hidden]`），弹窗收成单栏，而不是留一条空白。右栏也**不再有自建的 View on Instagram 按钮** —— embed 自己就带了回 Instagram 的链接，再加一个是冗余。

数据**在 liquid 渲染时从 `all_products[handle]` 实时取**，不走 metafield：

- 价格会变，而 metafield 是快照，存进去迟早过期；
- 货币格式必须跟随店铺设置，`| money` 只有在 liquid 里才拿得到；
- 多变体价格不同时用 `price_varies` 判断并输出 `From …`。

JSON 里给的是**已经格式化好的字符串**，JS 直接显示，不做货币计算。商品被删除时 `all_products` 取不到，该条直接不输出；数组末尾固定补一个 `null` 保证 JSON 合法 —— 这里不能靠 `forloop.last` 决定逗号，因为被跳过的商品什么都不输出，最后一个商品若正好被删就会生成 `[{…}null]` 这种非法结构。渲染侧本来就会跳过没有 `url` 的条目。

卡片用 DOM API 逐个 `createElement` + `textContent` 构建，不拼 HTML 字符串：标题来自店铺数据，拼字符串等于给自己开一个注入口子。

**主题按钮样式会污染卡片**：`.igv__play` 是 `position: absolute; inset: 0` 的全卡覆盖元素，主题里一条 `button:hover { background: <主题色> }` 就能让整张卡变色（macfox 主题色是黄的，表现就是 hover 全黄）。修法是把 `.igv .igv__play` 的 `:hover` / `:focus` / `:focus-visible` / `:active` 全部钉死成 `background: transparent !important`（连 `border` / `box-shadow` / `color` / `text-decoration` / `transform` 一起钉）。`.igv__arrow`（要保持白底）与 `.igv__dot`（要保持 `currentcolor`）同理，各有一组同样的防御规则。这里的 `!important` 是有意为之：主题的按钮样式本身经常带 `!important`，光靠提高选择器权重赌不赢。

**轮播控件**分两套布局：

- 桌面端：箭头绝对定位、竖直居中压在轨道左右边缘，圆点指示器 CSS 隐藏（一屏能看好几条，圆点没有信息量）；
- 移动端（≤749px）：`.igv__viewport` 变成 `flex-wrap`，轨道单独占一行，箭头改 `position: static` 用 `order` 排到轨道下方靠左，圆点跟在箭头后面。用 `order` 而不是改 DOM 顺序，桌面端才能继续复用同一组按钮。

箭头到边界是 `disabled` 而不是 `hidden`：隐藏会让另一侧按钮的位置发生跳动。**liquid 里 prev 按钮的初始态也必须是 `disabled`**（轨道从 `scrollLeft = 0` 开始）—— 服务端渲染成 `hidden` 而 JS 只切 `disabled` 的话，`hidden` 永远没人清，左箭头会一直不显示。

圆点按「屏」生成而不是按条目 —— 列数由 CSS 变量控制，条目数和可翻页数不是一回事，所以页数只能在浏览器里按 `scrollWidth / clientWidth` 算，服务端渲染不出来。图片是懒加载的，`window.load` 后会重算一次页数。

`instagram-videos.js` 有一条 **10000 字节（未压缩）**的硬线，当前 9996，余量 4。这条线的确切来源已经查清：不是[官方限额表](https://shopify.dev/docs/apps/build/online-store/theme-app-extensions/configuration)里那个标着 Suggested 的 10KB，而是 `shopify app deploy` 跑的 theme check 规则：

```
[error]: AssetSizeAppBlockJavaScript
The file size for 'instagram-videos.js' (10265 B) exceeds the configured threshold (10000 B)
```

它是 **error 级别但不阻塞发布** —— 10265 字节那次照样发布成功了，只是留下一条红色告警。既然是官方 error，就当硬线对待。改完必须重新量：`(Get-Item …\instagram-videos.js).Length`。

注释写英文也是为了这条线：一个中文字符 3 字节，注释里的中文曾经占掉一千多字节的余量。空间实在不够时优先压注释密度而不是删掉「为什么」，必要时把长解释挪到本文档。

## 同步与转存

同步流程：拉取 Instagram 媒体 → 差量落库 → 推进 R2 封面转存。

- 分页按游标翻到底，游标重复即中止，防止死循环；上限由 `INSTAGRAM_FEED_FETCH_ALL_ITEMS` 控制（默认 2000），单页 50 条；
- Instagram Login 首次请求带 `like_count` / `comments_count`，部分账号取不到时降级为精简字段重试一次；
- 已转存成功且元数据未变的条目直接跳过。IG 的媒体地址每次同步都带新签名，若逐条比对会让每次同步退化成上千次无意义写入；
- 转存状态机 `pending → processing → ready / failed`。`processing` 是占位状态，进程中断会留在这里，超过 `INSTAGRAM_FEED_MIRROR_STALE_SECONDS`（默认 300）后下一轮重新捞出重试；
- 视频取 `thumbnail_url` 当封面（回退 `media_url`），图片与轮播取 `media_url`（回退 `thumbnail_url`）。两个都拿不到才失败，错误码文案为「Instagram 没有返回可用的图片地址。」；
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
| 旧视频对象清理 | `instagram-feed:purge-mirrored-videos` | 一次性命令，转存改为只搬封面后收尾：删 R2 遗留视频对象、清空 `video_key` / `video_url`、把缺封面的 `ready` 条目重置为 `pending`。支持 `--store=` / `--dry-run` / `--chunk=` |
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
