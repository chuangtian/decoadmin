# Instagram 内容 Shopify App 对接契约

## 环境映射

| 环境 | Client ID | Handle | 应用域名 |
| --- | --- | --- | --- |
| `local` | `d3446448682d2950aa75cea4a399d50f` | `deco-instagram-feed-local` | `https://wendy-interim-classic-segment.trycloudflare.com`（当前临时地址） |
| `test` | 由 `INSTAGRAM_FEED_TEST_CLIENT_ID` 提供，尚未创建 | `deco-instagram-feed-test` | `https://testadmin.decomkt.com` |
| `production` | 由 `INSTAGRAM_FEED_PRODUCTION_CLIENT_ID` 提供，尚未创建 | `deco-instagram-feed` | `https://admin.decomkt.com` |

运行环境通过 `INSTAGRAM_FEED_ENVIRONMENT=local|test|production` 选择。对应 Client Secret 只放在未跟踪的环境变量中：`INSTAGRAM_FEED_<ENV>_CLIENT_SECRET`，也可使用当前环境公共回退变量 `INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET`。

测试与生产是 Dev Dashboard 里各自独立的 Shopify App，尚未创建，因此 `client_id` 默认留空；配置未补齐时后端一律返回 `INSTAGRAM_FEED_APP_NOT_CONFIGURED`（503），不会向 Shopify 发起任何调用。

三套 App 的固定授权集合均为：`read_products`。App 只需要解析关联商品的标题与 handle；写入自己的 app-data metafield 不需要额外 scope。后台 token exchange 后校验响应 `scope`，并通过 `currentAppInstallation.accessScopes` 再次核验安装权限；缺少必要权限时返回 `SHOPIFY_REQUIRED_SCOPES_MISSING`，不会继续建立会话。

本 App **不使用 App Proxy**：前台内容通过 app-data metafield 在服务端渲染，店面不回请 DecoAdmin。

## Meta 授权凭证

两条授权路线至少配一种，也可以都配（后台会同时显示两个连接入口）：

| 路线 | provider | 环境变量 |
| --- | --- | --- |
| Instagram 账号授权 | `instagram_login` | `INSTAGRAM_FEED_INSTAGRAM_APP_ID`、`INSTAGRAM_FEED_INSTAGRAM_APP_SECRET` |
| Facebook 主页授权 | `facebook_login` | `INSTAGRAM_FEED_FACEBOOK_APP_ID`、`INSTAGRAM_FEED_FACEBOOK_APP_SECRET` |

这是 Meta 后台里两组不同的凭证，不要混用。回调地址默认由当前环境应用域名推导，可用 `INSTAGRAM_FEED_INSTAGRAM_REDIRECT_URI` 与 `INSTAGRAM_FEED_FACEBOOK_REDIRECT_URI` 覆盖，且必须与 Meta 后台登记的地址完全一致。

App ID、App Secret 和 Facebook 登录配置 ID 现在也可以在后台「系统管理 → Instagram 与存储」页面维护（`system_settings` 的 `instagram_meta` 分组，需要 `system.settings.update` 权限）。后台有值时覆盖 `.env`，后台留空则回退到 `.env`。App Secret 加密保存且保存后不再回显，留空提交表示保持原值。

## Cloudflare R2

Instagram CDN 链接带签名会过期，同步后必须把视频与封面转存到 R2，对外用绑定在桶上的自定义域名给永久地址。相关变量：`INSTAGRAM_FEED_R2_ACCOUNT_ID`、`INSTAGRAM_FEED_R2_ACCESS_KEY_ID`、`INSTAGRAM_FEED_R2_SECRET_ACCESS_KEY`、`INSTAGRAM_FEED_R2_BUCKET`、`INSTAGRAM_FEED_R2_PUBLIC_BASE_URL`。

这五项同样可以在后台「系统管理 → Instagram 与存储」维护（`system_settings` 的 `instagram_r2` 分组），优先级与回退规则同上：后台有值覆盖 `.env`，留空回退 `.env`。Secret Access Key 加密保存、不回显。改动保存后立即生效，不需要重新构建镜像或重启容器。

R2 未配置时同步只拉取元数据、不转存，且未转存的内容不会发布到前台。对象 key 为 `{环境}/{店铺域名}/videos/{ig_media_id}.mp4` 与 `{环境}/{店铺域名}/posters/{ig_media_id}.jpg`，按环境与店铺隔离，重复转存是覆盖而不是堆积。

转存实现不把文件读进内存：下载走 HTTP 客户端 `sink` 落临时文件，上传走文件流，单文件上限由 `INSTAGRAM_FEED_MIRROR_MAX_OBJECT_BYTES` 控制（默认 200MB）。R2 请求使用手写 AWS SigV4 签名，不引入 aws-sdk。

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

尚未 bootstrap 时，后台发布会返回 `INSTAGRAM_FEED_APP_SESSION_MISSING`（409），页面同时给出提示。

## Meta OAuth 回调

```http
GET /instagram-feed/oauth/instagram/callback?code=...&state=...
GET /instagram-feed/oauth/facebook/callback?code=...&state=...
```

这两个路由公开且没有 DecoAdmin 会话，靠一次性 `state` 还原店铺。`state` 复用 DecoAdmin 既有 `oauth_states` 表：明文只出现在授权链接里，库里只存 sha256，带 15 分钟过期与 `consumed_at` 一次性消费标记，因此除了防伪造还能防重放。`payload.purpose` 固定为 `instagram_feed_meta_oauth`，且校验 `payload.environment` 与当前环境一致。

授权在新窗口完成，回调返回一段极简 HTML：展示结果、向 `window.opener` 发送 `{ type: 'instagram-feed-auth', ok: boolean }`，成功后 1.5 秒自动关闭。后台收到消息后局部刷新账号数据。

Token 生命周期：

- `instagram_login`：短期 token → 60 天长效 token，剩余有效期少于 `INSTAGRAM_FEED_REFRESH_WHEN_DAYS_LEFT` 天（默认 10）时自动续期；续期失败不阻断同步，继续用旧 token 试一次。
- `facebook_login`：短期用户 token → 60 天长效用户 token → 从 `/me/accounts` 取主页 token。主页 token 不过期。若授权者管理多个关联了 IG 的主页，账号进入 `needs_page_selection` 状态，回后台选择后才可用。

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

## DecoAdmin 后台

所有后台路由都显式携带 `{organization}` 与 `{store}`，并在控制器内再次校验用户范围和 RBAC：

- `GET /organizations/{organization}/stores/{store}/instagram-feed`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/connect`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/select-page`
- `DELETE /organizations/{organization}/stores/{store}/instagram-feed/account`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/sync`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/mirror`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/publish`
- `GET|POST|PUT|DELETE /organizations/{organization}/stores/{store}/instagram-feed/galleries[/{gallery}[/items|/order]]`
- `POST /organizations/{organization}/stores/{store}/instagram-feed/media/{media}/retry-mirror`
- `PUT /organizations/{organization}/stores/{store}/instagram-feed/media/{media}/products`

`connect` 返回 JSON（授权链接需要新窗口打开），其余动作走 Inertia flash。前端 POST 必须带 `X-XSRF-TOKEN`（取自 `XSRF-TOKEN` cookie）。

权限种子：

- `instagram_feed.view`
- `instagram_feed.connect`
- `instagram_feed.sync`
- `instagram_feed.gallery.manage`
- `instagram_feed.publish`

## 结构化错误

```json
{
  "error": {
    "code": "INSTAGRAM_ACCOUNT_NOT_CONNECTED",
    "message": "还没有连接 Instagram 账号。"
  }
}
```

常见错误码：`INSTAGRAM_FEED_APP_NOT_CONFIGURED`、`INSTAGRAM_FEED_APP_REGISTRY_CONFLICT`、`INVALID_SHOPIFY_ID_TOKEN`、`SHOPIFY_REQUIRED_SCOPES_MISSING`、`SHOPIFY_APP_NOT_INSTALLED`、`INSTAGRAM_FEED_APP_SESSION_MISSING`、`SHOPIFY_APP_SESSION_INVALID`、`STORE_NOT_CONNECTED`、`STORE_ACCESS_DENIED`、`INVALID_OAUTH_STATE`、`OAUTH_PROVIDER_MISMATCH`、`PROVIDER_NOT_CONFIGURED`、`INSTAGRAM_ACCOUNT_NOT_CONNECTED`、`INSTAGRAM_ACCOUNT_NOT_READY`、`FACEBOOK_NO_INSTAGRAM_ACCOUNT`、`FACEBOOK_PAGE_NOT_FOUND`、`FACEBOOK_AUTHORIZATION_EXPIRED`、`GALLERY_NOT_FOUND`、`GALLERY_NAME_REQUIRED`、`INVALID_PRODUCT_GID`、`R2_NOT_CONFIGURED`、`FEED_PAYLOAD_TOO_LARGE`、`FEED_PUBLISH_FAILED`、`INVALID_WEBHOOK_HMAC`。

## 数据库迁移

`database/migrations/2026_08_25_030000_create_instagram_feed_tables.php` 创建：

- `instagram_accounts`：每店铺一条授权记录。`access_token_encrypted`、`fb_user_token_encrypted` 走 Eloquent encrypted cast。
- `instagram_media`：同步下来的媒体与转存进度。唯一键 `[store_id, ig_media_id]`。
- `instagram_galleries`：展示组。唯一键 `[store_id, handle]`，`handle` 是随机短哈希而非组名派生，改名不影响主题引用。
- `instagram_feed_installations`：本 App 的店铺会话，保存 AppInstallation GID 与加密的 offline token。
- `instagram_gallery_items`：组内成员与组内顺序。唯一键 `[gallery_id, media_id]`。

原 Prisma schema 中的 `InstagramMedia.enabled` / `position` 是迁移期遗留字段（编排已全部走 GalleryItem），未迁移；`Session` 表由 Laravel 自身会话机制取代。
