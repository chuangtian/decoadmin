# 学生优惠 Shopify App 对接契约

## 环境映射

| 环境 | Client ID | Handle | App Proxy 店面路径 | 应用域名 |
| --- | --- | --- | --- | --- |
| `local` | `89f4215fdf2d0ffa9be3c7586fe50234` | `deco-student-discount-local` | `/apps/deco-student-local` | `https://wendy-interim-classic-segment.trycloudflare.com`（当前临时地址） |
| `test` | `179bfe6a970b5daabc4f6344bcd2733b` | `deco-student-discount-test` | `/apps/deco-student-test` | `https://testadmin.decomkt.com` |
| `production` | `030f66008921f9d200ac057ef03fa163` | `deco-student-discount` | `/apps/deco-student-discount` | `https://admin.decomkt.com` |

运行环境通过 `STUDENT_DISCOUNT_ENVIRONMENT=local|test|production` 选择。对应 Client Secret 只放在未跟踪的环境变量中：`STUDENT_DISCOUNT_<ENV>_CLIENT_SECRET`，也可使用当前环境公共回退变量 `STUDENT_DISCOUNT_SHOPIFY_CLIENT_SECRET`。

三套学生优惠 App 的固定授权集合均为：`read_discounts, write_discounts, read_products, write_app_proxy`。后台 token exchange 后校验响应 `scope`，并通过 `currentAppInstallation.accessScopes` 再次核验安装权限；缺少任一必要权限时返回 `SHOPIFY_REQUIRED_SCOPES_MISSING`，不会继续写 metafield。

Shopify TOML 的 App Proxy 目标统一配置为：

```text
/api/shopify-app/student-discounts/proxy
```

## App Home：连接检查

```http
GET /api/shopify-app/student-discounts/connection?shop={shop}.myshopify.com
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
      "organization_id": 1,
      "name": "Macfox US",
      "shopify_domain": "macfox-us.myshopify.com",
      "status": "active"
    }
  }
}
```

无效 ID Token 返回 `401` 和 `X-Shopify-Retry-Invalid-Session-Request: 1`，错误码为 `INVALID_SHOPIFY_ID_TOKEN`。

## App Home：安装 bootstrap

```http
POST /api/shopify-app/student-discounts/bootstrap?shop={shop}.myshopify.com
Authorization: Bearer {Shopify OIDC ID Token}
Accept: application/json
```

服务端验证 ID Token 后，将其交换为临时 online Admin API token；token 不保存、不回显。交换结果及当前安装必须覆盖 `read_discounts`、`write_discounts`、`read_products`、`write_app_proxy`。随后查询 `currentAppInstallation.id`，并用 `metafieldsSet` 幂等写入：

```json
{
  "namespace": "deco_student_discount",
  "key": "proxy_path",
  "type": "single_line_text_field",
  "value": "/apps/deco-student-local"
}
```

成功响应包含 `environment`、`store`、`app_installation_id` 和当前环境 `proxy_path`。Theme App Extension 可通过 `app.metafields.deco_student_discount.proxy_path.value` 读取。

## 店面 App Proxy

所有 GET/POST 请求必须由 Shopify App Proxy 转发，并携带 Shopify 添加的 `shop`、`timestamp` 和 `signature` 查询参数。服务端使用当前环境学生优惠 App Client Secret 计算 SHA-256 HMAC，校验五分钟时间窗，再由已验证的 `shop` 解析 Store。请求体中的 `shop`、`organization_id`、`store_id` 一律忽略。

下列 `/api/shopify-app/.../proxy` 是 Shopify 转发后的后端目标；Theme App Extension 在店面应请求当前环境 `/apps/...` 路径，例如 `/apps/deco-student-local/claims`。

### 获取活动入口信息

```http
GET /api/shopify-app/student-discounts/proxy?shop=...&timestamp=...&signature=...
```

```json
{
  "data": {
    "environment": "local",
    "shop": "macfox-us.myshopify.com",
    "campaign_enabled": true,
    "proxy_path": "/apps/deco-student-local",
    "claim_endpoint": "/apps/deco-student-local/claims"
  }
}
```

### 提交申请

```http
POST /api/shopify-app/student-discounts/proxy/claims?shop=...&timestamp=...&signature=...
Content-Type: multipart/form-data
```

字段：

- `name`：上传学生证时必填，2–120 个字符；支持常见中英文姓名字符、空格及常用分隔符；教育邮箱快速验证不提交该字段；
- `email`：必填，RFC 邮箱，最长 320；
- `privacy_consent`：上传学生证时必填，必须为 HTML/Laravel accepted 语义的同意值（建议表单 `1`）；后台只记录同意时间；教育邮箱快速验证不提交该字段；
- `idempotency_key`：必填，8–120 位，只允许字母、数字、`.`、`_`、`:`、`-`；
- `evidence`：非教育邮箱必填；单张 JPG/JPEG/PNG/WebP，最大 5MB。

教育邮箱快速验证使用 JSON，仅提交 `email` 与 `idempotency_key`。邮箱匹配当前店铺教育域名规则时直接发码；不匹配且未上传证件时返回 `422 EVIDENCE_REQUIRED`，店面据此显示学生证入口。

学生证申请使用 `multipart/form-data`，必须同时提交 `name`、`email`、`privacy_consent`、`evidence` 与 `idempotency_key`。缺少姓名或隐私同意时返回 `422 VALIDATION_FAILED`，服务端不会自动填充默认值。

同一已验证 Store 与请求 IP 每小时最多提交 5 次。限流发生在 App Proxy 签名和 Store 解析之后，服务端使用 Store ID 与 IP 的不可逆 HMAC 作为限流键，不信任请求体中的 `shop`、`store_id` 或 `organization_id`。

同一 Store、同一规范化邮箱使用新的 `idempotency_key` 重新提交时，会创建独立申请。旧的 `pending` 申请转为 `voided`，活跃证件字段在同一事务中清空；事务提交后立即尝试物理删除旧文件，失败则通过仅保存加密路径的持久化任务安全重试。只有确认文件不存在后才记录证件删除时间和成功审计。旧申请、关联关系及不含隐私正文的审计历史继续保留。重复使用相同 `idempotency_key` 且内容一致仍返回原申请，内容不一致仍返回 `IDEMPOTENCY_CONFLICT`。

也可直接 POST 到 App Proxy 根目标 `/api/shopify-app/student-discounts/proxy`，请求字段相同。

待人工审核返回 `202`：

```json
{
  "data": {
    "id": "claim-uuid",
    "status": "pending",
    "review_method": "ai",
    "submitted_at": "2026-08-25T10:00:00+00:00",
    "reviewed_at": null,
    "rejection_reason": null,
    "discount": null,
    "claim_token": "one-claim-query-token"
  }
}
```

教育邮箱或 AI 自动通过返回 `200`，`discount` 字段为：

```json
{
  "id": "discount-uuid",
  "code": "STUDENT-0123456789AB",
  "status": "unused",
  "usage_count": 0,
  "usage_limit": 1,
  "generated_at": "2026-08-25T10:00:00+00:00",
  "expires_at": "2026-09-01T10:00:00+00:00"
}
```

状态枚举：

- 申请：`pending`、`approved`、`rejected`、`voided`（已被新申请取代）；
- 优惠码：`unused`、`partially_used`、`used_up`、`expired`；
- 审核方式：`education_email`、`ai`、`manual`。

Gemini 仅判断图片整体是否呈现学生证或校园学生身份卡形态。学校名称/Logo、姓名、照片、学号等均可作为文档类型特征；它不会鉴别真伪、有效期、证件归属、填写姓名是否匹配或当前是否仍在校，也不会因 `TEST SAMPLE`、`NOT VALID`、样本或仿制水印否决。只有 `is_student_id=true` 且 `confidence` 达到后台阈值才自动通过，有效阈值最低为 80；其余识别结果或 API、超时、JSON、置信度异常均进入人工审核，永不自动拒绝。

### 查询申请/优惠码状态

```http
GET /api/shopify-app/student-discounts/proxy/claims/{claim_uuid}?claim_token={claim_token}&shop=...&timestamp=...&signature=...
```

`claim_token` 必须作为 App Proxy 签名的一部分。响应结构与提交申请的 `data` 相同，但不再次返回 `claim_token`。查询时会尽力同步 Shopify 的 `asyncUsageCount`。

### 结构化错误

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "提交内容不符合要求。",
    "fields": {
      "evidence": ["evidence 字段必须是 jpg、jpeg、png、webp 类型的文件。"]
    }
  }
}
```

常见错误码包括：`INVALID_APP_PROXY_SIGNATURE`、`INVALID_SHOPIFY_ID_TOKEN`、`SHOPIFY_REQUIRED_SCOPES_MISSING`、`STORE_NOT_AVAILABLE`、`STORE_NOT_CONNECTED`、`CAMPAIGN_DISABLED`、`EVIDENCE_REQUIRED`、`IDEMPOTENCY_CONFLICT`、`STUDENT_DISCOUNT_RATE_LIMITED`、`SHOPIFY_DISCOUNT_CREATE_FAILED`、`STUDENT_DISCOUNT_UNAVAILABLE`。

超过提交频率时返回 `429`，并携带秒数形式的 `Retry-After` 响应头：

```json
{
  "error": {
    "code": "STUDENT_DISCOUNT_RATE_LIMITED",
    "message": "提交过于频繁，请稍后重试。",
    "retry_after": 3598
  }
}
```

## DecoAdmin 后台

所有后台路由都显式携带 `{organization}` 与 `{store}`，并再次校验用户范围和 RBAC：

- `GET /organizations/{organization}/stores/{store}/student-discounts`
- `PUT /organizations/{organization}/stores/{store}/student-discounts/campaign`
- `POST /organizations/{organization}/stores/{store}/student-discounts/claims/{claim}/approve`
- `POST /organizations/{organization}/stores/{store}/student-discounts/claims/{claim}/reject`
- `GET /organizations/{organization}/stores/{store}/student-discounts/claims/{claim}/evidence`
- `PUT /settings/student-ai`（仅超级管理员）

权限种子：

- `student_discount.claim.read`
- `student_discount.view_evidence`
- `student_discount.approve`
- `student_discount.reject`
- `student_discount.campaign.manage`
- `student_discount.analytics.read`
- `student_discount.audit.read`

## 数据库迁移

`database/migrations/2026_08_25_020000_create_student_discount_tables.php` 创建：

- `student_discount_campaigns`
- `student_discount_claims`
- `student_discount_codes`

`database/migrations/2026_08_25_021000_create_student_discount_claim_idempotencies_table.php` 创建：

- `student_discount_claim_idempotencies`

`database/migrations/2026_08_27_000500_add_submission_identity_and_supersession_to_student_discount_claims.php` 增加：

- 申请人姓名与隐私同意时间；
- 旧申请的作废时间及指向新申请的关联。

`database/migrations/2026_08_27_000600_create_student_discount_evidence_deletions_table.php` 创建加密路径的证件清理任务表，用于事务提交后的物理删除、失败重试和安全状态审计；清理成功后路径与磁盘字段立即清空。

Gemini 设置继续存放在既有 `system_settings` 表的 `student_ai` section，API Key 通过 Eloquent encrypted cast 加密，前端只收到配置状态。
