# 学生优惠插件 Test 发布记录

发布日期：2026-08-26（Asia/Shanghai）

## 发布范围

- Shopify App：`deco-student-discount-test`
- Shopify App 版本：`deco-student-discount-test-3`
- DecoAdmin 分支：`test`
- DecoAdmin Test 发布提交：`991a2b0a4106fb0e83451cc57d0326253b211cbc`
- Test 地址：`https://testadmin.decomkt.com`
- 未修改或发布 Local、Production、`main`。

## Shopify App 验证与发布

- `shopify app config validate --config test --json`：通过，`valid=true`，无配置问题。
- `npm run build:test`：通过；App Home 和 Theme App Extension 均构建成功。
- 已发布版本 `deco-student-discount-test-3`。
- Test 配置使用独立 Client ID、应用域名、App Proxy 路径和 scopes；Secret 未写入仓库或本文档。

## DecoAdmin Test 更新

- staging 环境已设置 `STUDENT_DISCOUNT_ENVIRONMENT=test`。
- Test App Client Secret 已通过安全管道写入未跟踪的 staging 环境文件；仅验证 `configured=true`，未回显明文。
- 生产镜像构建、Laravel 缓存刷新、容器重建及内部健康检查通过。
- 数据库迁移检查完成：`Nothing to migrate`。
- App、Nginx、MySQL、Redis、Horizon、Scheduler 均正常启动。
- 外部 `/health` 返回 `200`。

## 新增入口与安全边界

- `GET /shopify-app/student-discounts`
  - 未登录请求返回 `302` 登录跳转，不再返回 `404`。
  - 登录后由后端根据 `shop` 定位 Store，并校验用户、Organization、Store 成员关系和 `student_discount.claim.read` 权限。
- `POST /api/shopify-app/webhooks`
  - 未签名请求返回 `401`，不再返回 `404`。
  - 使用当前学生优惠环境 Client Secret 校验 Shopify HMAC。
  - 仅支持 `app/uninstalled` 和 `app/scopes_update`。
  - 店铺仅取自 `X-Shopify-Shop-Domain`，不信任请求体中的 `shop`。
  - Webhook 按事件 UUID 和 payload hash 幂等；冲突返回 `409`。
  - 原始 payload 加密保存，且不会断开 DecoAdmin 主 Shopify 连接。
- `GET /api/shopify-app/student-discounts/connection`
  - 无有效 OIDC Token 返回 `401`，不再返回 `404`。
- `GET|POST /api/shopify-app/student-discounts/proxy`
  - 无有效 App Proxy 签名返回 `401`，不再返回 `404`。

## Gemini Test 数据库配置

- `gemini_api_key_configured=true`
- `gemini_model=gemini-2.5-pro`
- `auto_approval_threshold=80`
- `raw_value_contains_plaintext=false`

Gemini API Key 使用安全命令隐藏输入并通过项目加密 cast 保存；命令输出、日志和本文档均不包含 Secret。

## 测试结果

- `StudentDiscountTest` + `StudentDiscountShopifyAppEntryTest`：16 passed，182 assertions。
- 新入口独立测试：4 passed，58 assertions。
- PHP 语法检查：4 个相关 Controller/Service 文件通过。
- Pint：7 个相关文件通过。
- `git diff --check`：通过。
- Test 外部状态码：health `200`、App Home 未登录 `302`、connection 未授权 `401`、App Proxy 未签名 `401`、Webhook 未签名 `401`。

## 后续操作

- Shopify Test App 尚需在目标测试店铺安装或升级后，才能用真实 Shopify OIDC Token、App Proxy 签名和 Webhook 投递做端到端业务验证。
- App Home 连接成功后应继续按既有契约调用 `POST /api/shopify-app/student-discounts/bootstrap`，写入该环境的 `proxy_path` app-data metafield。
