# Deco 个性化推荐

`deco-personalization` 是独立的 Shopify App 工程。它只拥有 Shopify 配置、扩展、依赖和验证脚本；推荐策略、数据、权限和业务逻辑位于 DecoAdmin Laravel 后端。

## 环境边界

- 本机/worktree 仅用于代码、Docker 测试、离线构建和发布前校验，不是 Local Shopify App 环境。
- `shopify.app.local.toml` 仅为不可运行的结构占位，不得配置或发布。
- Test 是第一个真实运行环境，商家可见名称为 `Deco 个性化推荐测试`，技术 handle 为 `deco-personalization-test`。
- Production 在当前授权中不可创建、配置、发布、安装或部署；正式显示名称预留为 `Deco 个性化推荐`。

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

App Home 不包含 Shopify 凭证，也不连接 Local 或 Production。永久禁止店铺会在发出任何后端请求前被拒绝。

## Theme App Extension

`extensions/recommendations` 提供可添加到首页、商品页和购物车 JSON 模板的 App Block。区块只请求店铺同源 App Proxy，代理由 DecoAdmin 使用 Personalization 独立 Secret 验签，再返回已启用、当前店铺范围内的推荐结果。

按 Shopify 当前官方要求，App Proxy 使用 P0 最小 scope `write_app_proxy`。Theme App Extension 不读取或写入主题文件，因此不申请 `read_themes` / `write_themes`。区块不会自动加入任何主题，必须由商家在指定测试主题中添加并保存。

## Web Pixel

`extensions/web-pixel` 使用 Shopify 严格沙箱收集推荐曝光、点击、加购和结账完成事件。按 Shopify 当前官方要求，它使用 `write_pixels` 与 `read_customer_events`；其中 Pixel 查询所需的读取能力由 `write_pixels` 覆盖，不额外申请主题、客户、商品或订单写权限。

Pixel 只声明分析用途：`analytics = true`，营销、偏好和数据销售均关闭。DecoAdmin 只保存哈希后的 Shopify client/session 标识、已验证的组件/策略/商品引用及订单 ID；不保存 IP、邮箱、电话、姓名、原始 URL 或完整事件载荷。结账事件只能作为候选信号，后续收入归因必须与 Commerce Hub 已同步订单核对。

## 归因与分析

归因固定为 7 天窗口、最后一次推荐点击、只计算点击归因。结账 Pixel 订单 ID 必须与当前 Organization / Store 下的 Commerce Hub 非测试订单匹配；浏览器传入的金额不参与计算。每个订单最多生成一条归因，部分退款按订单总额减退款额冲销，取消或全额退款将订单和收入归因置为已冲销。

后台每五分钟运行一次店铺隔离的归因核对，并按店铺时区输出最近 30 天曝光、点击、加购、归因订单、净归因收入、AOV、每日趋势和展示位置表现。不同订单币种不混合汇总。

## 当前状态

App Home 工程与离线测试已建立。Test Client ID 和实际 Shopify 配置将在发布阶段只读定位现有 Test App 后补齐；Client Secret 只进入批准的 Secret/环境配置，不进入本目录。

## 离线校验

```shell
npm run check
```

该命令不连接 Shopify，不安装 App，也不修改任何环境。
