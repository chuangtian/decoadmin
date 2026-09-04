# Deco 个性化推荐

`deco-personalization` 是独立的 Shopify App 工程。它只拥有 Shopify 配置、扩展、依赖和验证脚本；推荐策略、数据、权限和业务逻辑位于 DecoAdmin Laravel 后端。

## 环境边界

- 本机/worktree 仅用于代码、Docker 测试、离线构建和发布前校验，不是 Local Shopify App 环境。
- `shopify.app.local.toml` 仅为不可运行的结构占位，不得配置或发布。
- Test 是第一个真实运行环境，商家可见名称为 `Deco 个性化推荐测试`，技术 handle 为 `deco-personalization-test`。
- Production 使用独立 Shopify App，正式显示名称为 `Deco 个性化推荐`，技术 handle 为 `deco-personalization`；与 Test 配置、凭证和发布流程完全隔离。

## 工程模式

工程与运行模式复用 Student Discount App 已验证的结构：

```text
App Home / Theme App Extension / Web Pixel
                    ↓
             DecoAdmin Controller
                    ↓
       Personalization Business Service
                    ↓
 Commerce Hub shared data / Personalization models
```

所有 shop、Organization 和 Store 都由后端从已验证身份动态解析，不在代码中写死商店。

## App Home

`extensions/app-home` 复用 Student Discount 的身份模式：从 Shopify 获取 ID token，动态解析 shop，固定请求 Test DecoAdmin 源站，再由后端校验签名、audience、Organization、Store 与 RBAC。前端解析不代替后端验签。

App Home 不包含 Shopify Secret；它按 Shopify Client ID 选择固定的 Test 或 Production DecoAdmin 源站。永久禁止店铺会在发出任何后端请求前被拒绝。

## Theme App Extension

`extensions/recommendations` 提供可添加到首页、商品页和购物车 JSON 模板的 App Block。区块只请求店铺同源 App Proxy，代理由 DecoAdmin 使用 Personalization 独立 Secret 验签，再返回已启用、当前店铺范围内的推荐结果。

按 Shopify 当前官方要求，App Proxy 使用 P0 最小 scope `write_app_proxy`。Theme App Extension 不读取或写入主题文件，因此不申请 `read_themes` / `write_themes`。区块不会自动加入任何主题，必须由商家在指定测试主题中添加并保存。

## Web Pixel

`extensions/web-pixel` 使用 Shopify 严格沙箱收集推荐曝光、点击、加购和结账完成事件。按 Shopify 当前官方要求，它使用 `write_pixels` 与 `read_customer_events`；其中 Pixel 查询所需的读取能力由 `write_pixels` 覆盖，不额外申请主题、客户、商品或订单写权限。

Pixel 只声明分析用途：`analytics = true`，营销、偏好和数据销售均关闭。DecoAdmin 只保存哈希后的 Shopify client/session 标识、已验证的组件/策略/商品引用及订单 ID；不保存 IP、邮箱、电话、姓名、原始 URL 或完整事件载荷。结账事件只能作为候选信号，后续收入归因必须与 Commerce Hub 已同步订单核对。

## 推荐优惠

策略后台可以读取现有 Shopify 基础折扣码，并为已选推荐商品创建或编辑百分比折扣。该能力最小新增 `read_discounts` 与 `write_discounts`，只通过 Personalization App 自有安装令牌访问当前 Store；不复用 Student Discount 的表、代码或发布生命周期。折扣失效或授权缺失不会阻止基础推荐展示。

## Checkout UI Extension

`extensions/checkout-trust-badges` 与 `extensions/checkout-recommendations` 使用 API `2026-07` 的官方 `purchase.checkout.block.render` target。信任信息建议放在 `WALLETS1`，递进推荐建议放在订单摘要 `ORDER_SUMMARY2`；实际位置由商家在 Checkout Editor 添加和调整，不能保证与参考图像素级一致。

后台选择一个 Commerce Hub 已同步的 Shopify Collection，并可选配置递进推荐数量上限（1–1000；留空表示遍历整个集合）。扩展始终一次只显示一个商品，加入成功后获取下一个；这不是固定商品槽位。扩展按 Shopify Collection 默认顺序读取当前 Market 下的商品，每个商品选择第一个可售变体；已在购物车、缺货、不可售、加入失败或已展示的商品不会重复循环。达到可选上限或没有合格候选时区块隐藏。两个扩展默认关闭，任何后端、Storefront API、Cart Lines API 或分析事件失败都不得阻止结账。

Checkout 扩展新增的是 `api_access` 与 `network_access` capability，不增加 Admin API scope，也不申请客户、订单、商品或主题写权限。平台限制和官方依据见 [`CHECKOUT_EXTENSION.md`](./CHECKOUT_EXTENSION.md)。

## 归因与分析

归因固定为 7 天窗口、最后一次推荐点击、只计算点击归因。结账 Pixel 订单 ID 必须与当前 Organization / Store 下的 Commerce Hub 非测试订单匹配；浏览器传入的金额不参与计算。每个订单最多生成一条归因，部分退款按订单总额减退款额冲销，取消或全额退款将订单和收入归因置为已冲销。

后台每五分钟运行一次店铺隔离的归因核对，并按店铺时区输出最近 30 天曝光、点击、加购、归因订单、净归因收入、AOV、每日趋势和展示位置表现。不同订单币种不混合汇总。

## 数据保留与隐私生命周期

- 匿名原始推荐事件与逐单归因明细保留 90 天；删除前先生成不含访客标识的日聚合。
- 匿名日聚合保留 13 个月；Personalization 审计保留 365 天。
- `app/uninstalled` 后 48 小时安排在线 Personalization 数据清理；有效 `shop/redact` 立即安排清理，小时任务执行，均早于 72 小时上限。
- `customers/data_request` 与 `customers/redact` 返回“未持有客户数据”，不保留 Webhook 中的客户 ID、邮箱、电话或原始载荷；三个官方合规主题都由同一 HMAC 验证入口处理。
- 备份属于基础设施策略，Test/Production 发布清单必须确认 Personalization 备份副本在 30 天内过期；应用代码不会把备份恢复到在线保留期之外。

## 当前状态

Test App 工程、App Home、Theme App Extension、Web Pixel 与 Checkout UI Extension 均已建立。Client Secret 只进入批准的 Test Secret/环境配置，不进入本目录。

## 离线校验

```shell
npm run check
```

该命令不连接 Shopify，不安装 App，也不修改任何环境。

## 发布文档

- Test 发布与回滚：[`TEST_RELEASE.md`](./TEST_RELEASE.md)
- 极简策略编辑、商品选择与永久删除：[`STRATEGY_WORKFLOW.md`](./STRATEGY_WORKFLOW.md)
- Production 规划与未来授权闸门：[`PRODUCTION_RELEASE.md`](./PRODUCTION_RELEASE.md)
