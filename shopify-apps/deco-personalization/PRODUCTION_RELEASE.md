# Deco 个性化推荐 Production 发布运行手册

本文档只整理未来 Production 发布流程，不构成创建、配置、部署、发布、安装或修改 Production 的授权。正式执行前，必须按外部动作分别取得明确批准；本次 Test 验收不能自动授权 Production。

## 永久边界

- Production 站点固定为 `https://admin.decomkt.com`，不得与 Test、代码工作区或不可运行的 Local Shopify App 占位配置混用。
- 永久 denylist 中的商店不得作为 Production 安装、主题、Pixel、Webhook 或发布验证目标；具体域名只由专用安全守卫维护。
- 不修改或发布 Commerce Hub、Student Discount、Instagram Feed；Personalization 继续只复用 Commerce Hub 的共享商品、库存、订单及 Organization/Store 隔离。
- 目录和技术标识使用 `deco-personalization`；商家显示名称使用 `Deco 个性化推荐`，不得把中文显示名称写成技术 handle。
- Client Secret、访问令牌、Cookie、授权头和私钥只进入批准的 Secret 配置，绝不进入 Git、构建产物、命令输出、日志或文档。

## 执行前必须重新确认

1. 明确批准创建或复用 Production Shopify App，并只读确认目标 Dev Dashboard 组织、App 唯一性、分发方式和目标商店资格。
2. 明确批准 Production DecoAdmin 后端部署，并选择 Production 目录、环境文件、数据库和回滚版本。
3. 明确批准 Production App 配置校验与版本发布。
4. 对每个目标商店分别批准安装或更新；运行时仍必须动态解析 shop、Organization 和 Store，不得硬编码店铺。
5. 对每个目标主题分别批准复制、修改、预览或发布；优先使用未发布主题副本，不直接修改已发布主题。
6. Smart Cart 必须另行人工启用；完成兼容性和桌面/移动预览不等于自动授权启用。

任一目标、组织、商店、主题、权限或 Secret 不确定时立即停止。Custom/Public distribution 在真正向其他商店分发前按当时 Shopify 平台规则单独处理。

## Production 配置合同

- `shopify.app.production.toml` 在获批前保持不可运行占位；不得提前填入 Client ID、URL、Webhook、App Proxy 或发布命令。
- Application URL 预期使用 `https://admin.decomkt.com/shopify-app/personalization`；回调、Webhook、App Proxy 和事件入口必须全部使用同一 Production 源站。
- P0 最小显式 scopes 预期仅为 `write_app_proxy`、`write_pixels`、`read_customer_events`。发布前必须以当时 Shopify 官方文档和 CLI 校验为准；不得增加 P0 无关权限。
- App Proxy 的 Production subpath 必须独立于 Test；最终值需在 Production App 唯一确定后写入配置、后端和发布门禁，三处必须一致。
- App-specific Webhook 仅包括 `app/uninstalled`、`app/scopes_update`；合规 Webhook 包括 `customers/data_request`、`customers/redact`、`shop/redact`。
- Theme App Extension 不申请 `read_themes` 或 `write_themes`；主题区块由获批的商家操作添加并保存。

## 固定发布顺序

1. 从已统一检查的提交建立 Production 发布候选；确认差异不包含另外三个 Shopify App。
2. 在代码工作区完成 Docker 全量后端测试、前端生产构建和 `shopify-apps/deco-personalization` 离线检查。
3. 只读确认 Production App 唯一存在；按批准方式注入 Client ID，Secret 只写入 Production Secret 配置。
4. 在明确选中的 Production DecoAdmin 目录和环境文件中创建数据库与源码备份，备份不超过 30 天。
5. 构建不可变 App/Nginx 镜像，运行一次性 setup/迁移和 `personalization:release-check`；门禁只报告 Secret 是否存在。
6. 先部署 Production DecoAdmin 后端，验证 `/health`、静态资源、Personalization 路由、Horizon、Scheduler、迁移和回滚目录。
7. 使用 Production Shopify 配置运行官方 CLI config validate 和 App build；配置、scope 或扩展校验失败时不得发布。
8. 创建未发布 App version，复核配置和扩展清单后再单独批准 release；App version 不发布后端代码。
9. 只在明确批准且不在 denylist 的目标商店安装；验证 App Home ID token、动态 shop 解析、安装记录、App Proxy 和 Web Pixel。
10. 在未发布主题副本添加推荐 App Block，设置经过预览的组件 UUID；完成首页、商品页和购物车的桌面/移动验证。
11. Smart Cart 默认关闭。逐项通过未发布副本、App Embed、浏览器抽屉、购物车入口、Cart API 和加购/数量/删除行为检查后，人工确认预览并单独启用。
12. 完成曝光、点击、加购、结账候选、7 天最后推荐点击归因、退款/取消冲销、AOV 和跨店隔离验收，再决定是否扩大安装范围。

## 隐私与数据保留

- 原始匿名事件和逐单归因保留 90 天；匿名日聚合保留 13 个月；Personalization 审计保留 365 天。
- 不存储原始 IP、邮箱、电话、姓名、完整 URL、User-Agent、原始 Pixel/Webhook 载荷或浏览器提供的订单金额。
- `app/uninstalled` 后 48 小时安排在线清理；有效 `shop/redact` 立即安排清理，均不得超过 72 小时。
- 备份副本 30 天内过期；恢复时不得把已超过在线保留期的数据重新带回服务。
- 每个入口继续强制 User、Organization、Store、RBAC、HMAC/ID token 和 denylist 校验。

## 验收证据

- 记录代码提交、后端镜像、App version、Production App ID（不含 Secret）、目标 shop、主题 ID 和操作人。
- 保存 Test/Production 配置校验、构建、测试、迁移、release-check、健康检查和 Horizon/Scheduler 的成功摘要。
- 证明 App Proxy 未签名返回 401、跨店组件不可读取、Web Pixel 只存哈希标识、Smart Cart 默认关闭且恢复接口返回 `shopify_default`。
- 新订单归因必须来自 Commerce Hub 同店非测试订单；无合格测试订单时不得伪造通过结果。

## 回滚顺序

1. Smart Cart 异常时先执行恢复 Shopify 默认购物车，再关闭目标主题 App Embed。
2. 推荐区块异常时移除目标主题 App Block，或回到修改前的未发布主题副本；不得影响其他主题或 App。
3. Web Pixel 异常时回退 Personalization App version 或断开该 App Pixel，不删除其他 App 客户事件。
4. Shopify App 配置或扩展异常时只回退 `deco-personalization` 的上一 Production App version。
5. DecoAdmin 异常时恢复上一组 Production App/Nginx 镜像和源码目录；数据库优先前向修复，不执行未经确认的破坏性 down migration。
6. 回滚后重新验证健康、队列、Scheduler、App Home、代理、Pixel 和数据保留任务，并记录审计结果。

任何 Production 步骤都必须在新的明确授权后执行；本手册本身不允许执行 Production。
