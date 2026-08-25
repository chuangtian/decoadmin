# Shopify 本地真实环境联调

本文说明如何通过 Docker Compose、Cloudflare Quick Tunnel 和 Shopify Dev Store 验证 decoadmin 的 OAuth 与 Connection Health。文档不包含 Client Secret、Access Token 或其他私密信息。

## 架构

```text
Shopify Dev Store
        ↓ HTTPS
Cloudflare Tunnel
        ↓ Docker network
Nginx
        ↓ FastCGI
Laravel
```

Cloudflare Tunnel 仅在启用 `tunnel` profile 时启动，普通本地开发不受影响。

## 前置条件

- Docker Desktop 已启动。
- Shopify Dev Dashboard 中已有专供本地联调的开发 App。
- 开发 App 与 Dev Store 属于可互相安装的 Shopify Organization。
- 项目根目录 `.env` 已配置该开发 App 的 Client ID 和 Client Secret。

不要把 `.env`、Client Secret 或 Shopify Access Token 提交到 Git。

## 启动本地服务与 Tunnel

启动完整开发环境和可选 Tunnel：

```bash
docker compose --profile tunnel up -d
```

查看 Cloudflare Quick Tunnel 日志：

```bash
docker compose logs -f cloudflared
```

日志会输出一个临时 HTTPS 地址，例如：

```text
https://example.trycloudflare.com
```

Quick Tunnel 没有固定域名或可用性保证。容器重建或网络中断后地址可能变化；地址变化时必须同步更新 `.env` 和 Shopify App Version。

普通开发不需要 Tunnel：

```bash
docker compose up -d
```

停止 Tunnel profile：

```bash
docker compose --profile tunnel stop cloudflared
```

## 环境变量

保留本地入口，同时单独配置 Shopify 外网入口：

```dotenv
APP_URL=http://localhost:8000

SHOPIFY_CLIENT_ID=your-development-app-client-id
SHOPIFY_CLIENT_SECRET=your-development-app-client-secret
SHOPIFY_API_VERSION=2026-07
SHOPIFY_REQUESTED_SCOPES=read_products
SHOPIFY_APP_URL=https://example.trycloudflare.com
SHOPIFY_REDIRECT_URI=https://example.trycloudflare.com/shopify/oauth/callback
```

修改后在 Docker 中刷新配置，并按项目约定重启 Vite：

```bash
docker compose exec -T app php artisan optimize:clear
docker compose restart vite
docker compose logs --tail=30 vite
```

本地仍通过 `http://localhost:8000` 访问；Shopify OAuth Callback 通过 Tunnel HTTPS 地址访问。外网请求使用构建后的前端资源，本地请求继续使用 Docker Vite 开发服务。

## Shopify App Version 配置

在 Shopify Dev Dashboard 打开开发 App，创建并发布一个 App Version：

```text
App URL
https://example.trycloudflare.com/shopify/launch

Redirect URL
https://example.trycloudflare.com/shopify/oauth/callback

Required scopes
read_products
```

decoadmin 当前不是 Shopify Admin Embedded App，因此关闭 Embedded App。用户从 Shopify 点击应用后，通过 `/shopify/launch` 使用现有 decoAdmin 账号登录，并自动进入已分配店铺的营销首页。Webhook API Version 与 `SHOPIFY_API_VERSION` 保持一致。

Redirect URL 必须与 `SHOPIFY_REDIRECT_URI` 完全一致，包括 HTTPS、域名、路径以及末尾是否带 `/`。

## OAuth 实际安装流程

1. 通过 Tunnel HTTPS 地址登录 decoAdmin。
2. 进入 `Shopify → 店铺管理 → 添加店铺`。
3. 输入店铺名称、准确且唯一的 `*.myshopify.com` Domain 和环境，先创建店铺。
4. 创建完成后进入店铺的“应用”页，确认状态为“未安装”。
5. 由超级管理员或组织管理员点击“安装应用”。
6. 在 Shopify Plus Organization 内的目标店铺确认 Custom distribution App 安装。
7. Shopify 回调 `/shopify/oauth/callback`。
8. Laravel 验证一次性 State、Cookie、Shop Domain 与 HMAC。
9. Laravel 交换并加密保存 Store Access Token，更新预创建的店铺和安装记录；OAuth 回调绝不自动创建店铺。

不要刷新旧的 Shopify OAuth 错误页或重复使用旧 State；回到店铺详情重新发起 OAuth。

## Connection Health 验证

OAuth 成功后进入：

```text
Store Detail → Shopify → Verify Connection
```

成功结果应为：

- Connection Status 为 `connected`。
- `last_verified_at` 与 `last_api_check` 更新。
- API Version 与当前配置一致。
- Shopify GraphQL `shop` 查询返回真实店铺 ID、名称和 myshopify Domain。

批量检测命令：

```bash
docker compose exec -T app php artisan shopify:check-connections
```

## 异常状态验收

- 无效或撤销的 Token：Shopify 返回 `401/403`，Connection 标记为 `invalid`。
- 限流、网络错误或临时 Shopify API 失败：Connection 标记为 `warning`。
- App 卸载：调用 Shopify `appUninstall` 真正卸载，撤销并清除 Token，Connection 标记为 `disconnected`。
- `invalid` 或 `disconnected` 状态重新授权：复用现有 OAuth 流程，更新原 Connection 并恢复为 `connected`。

卸载后立即停止同步任务；客户个人数据在 48 小时后清除或匿名化，店铺级应用配置在 30 天后清除。期间重新安装会取消待执行的清理。

异常测试不得把真实 Token、Secret 或完整授权 URL写入日志或文档。对真实连接做破坏性测试时必须在可回滚事务中执行，确保原 Token 和 Connection 状态被恢复。

## 验收检查

```bash
docker compose ps
docker compose --profile tunnel ps
docker compose logs --tail=50 cloudflared
docker compose exec -T app php artisan test
docker compose exec -T vite npm run type-check
docker compose exec -T vite npm run build
docker compose exec -T app ./vendor/bin/pint
```

完成后确认：

- 本地入口和 Tunnel HTTPS 入口都能访问。
- Cloudflare 使用 HTTP/2 注册成功。
- Shopify Dev Store 安装与 OAuth Callback 成功。
- 数据库存在真实 Store、Connection、Installation 和已消费 OAuth State。
- Connection Health 对真实 Shopify GraphQL 请求返回 `connected`。
