# Deco 个性化推荐 Test 发布清单

本清单只适用于 `Deco 个性化推荐测试` / `deco-personalization-test`。Test 是第一个真实运行环境；本机仅运行代码、Docker 测试和离线构建。不得创建或运行 Local Shopify App，不得使用 Cloudflare 临时域名，不得执行任何 Production 操作。

## 固定目标与停止条件

- Dev Dashboard 组织必须是 `E-LINK TECHNOLOGY CO LTD`。
- 外部联调店必须解析为 `macfox-test-app.myshopify.com`。
- `Macfox Bike` / `macfoxebike.myshopify.com` 是永久禁止目标；任何解析结果为该店或目标不确定时立即停止。
- Test App 必须复用组织中唯一的 `Deco 个性化推荐测试`；该 App 已于 2026-08-28 创建，不得重复创建。
- Client Secret 只进入 Test 的未跟踪 Secret/环境配置，不进入命令参数、聊天、日志、Git 或本文档。
- Production 配置仍是不可运行占位，不得加入 Client ID、URL、Webhook、权限或发布命令。

## 固定发布顺序

1. 本机代码、Docker 测试、前端生产构建和 App 离线检查通过。
2. 合并阶段提交到统一 Test 发布提交，并记录提交 SHA；不得混入另外三个 Shopify App 的发布。
3. 在明确选中的 DecoAdmin Test 目录和 `.env.staging` 上构建镜像、备份数据库并运行迁移。
4. 部署 DecoAdmin Test 后端；验证 `/health`、路由、静态资源、Horizon 和 Scheduler。
5. 在 Test 容器运行 `php artisan personalization:release-check`；只接受通过结果，不显示 Secret。
6. 只读确认 Dev Dashboard 当前组织与现有 Test App，补齐 `shopify.app.test.toml` 的 Client ID，并让 App Home 固定同一 Client ID。
7. 运行官方 `shopify app config validate --config test --json`，随后执行 Test 构建。
8. 发布 Test App 新版本；不得发布 Production 或另一个 App。
9. 只在 `macfox-test-app` 安装或更新，打开 App Home 完成 bootstrap。
10. 只在未发布测试主题副本添加推荐 App Block、启用 Smart Cart App Embed；在 Shopify Checkout Editor 添加信任信息和递进推荐两个 Checkout App Block，并完成 Web Pixel 与端到端验收。

## 本机与 Shopify 配置校验

普通离线检查不会连接 Shopify：

```shell
npm run check
```

真实 Test 配置补齐后，所有 Shopify CLI 操作必须通过安全包装器。调用前只设置非 Secret 的目标门禁变量：

```shell
export PERSONALIZATION_TEST_SHOP=macfox-test-app.myshopify.com
export PERSONALIZATION_DEV_ORGANIZATION='E-LINK TECHNOLOGY CO LTD'
npm run validate:test
npm run build:test
```

发布还必须使用一次性显式确认值：

```shell
export PERSONALIZATION_TEST_RELEASE_CONFIRM=deco-personalization-test
npm run deploy:test
```

包装器只允许 `validate`、`build`、`deploy` 三种 Test 动作；`deploy` 在非交互流程中只传入 `--allow-updates`，不允许删除扩展。店铺、组织、确认值不匹配或命中 denylist 会在调用 Shopify CLI 前失败。

## Test 配置合同

- 显示名称：`Deco 个性化推荐测试`；handle：`deco-personalization-test`。
- Application URL：`https://testadmin.decomkt.com/shopify-app/personalization`。
- 最小 scopes 为 `write_app_proxy`、`write_pixels`、`read_customer_events`、`read_discounts`、`write_discounts`。其中折扣读取、创建和编辑只服务于已确认的推荐优惠功能。
- App Proxy：`/apps/deco-personalization-test` 指向 Test 后端 Personalization 代理入口。
- App-specific Webhook：`app/uninstalled`、`app/scopes_update`。
- 合规 Webhook：`customers/data_request`、`customers/redact`、`shop/redact`。
- `embedded = true`、Shopify managed install、禁止 CLI 自动改写 URL。
- 当前 Shopify CLI 强制随发布包含 App 配置；不保留已废弃的 `include_config_on_deploy` 字段。
- Theme App Extension 不读取或写入主题文件，因此不申请 `read_themes` / `write_themes`。
- Checkout UI Extension 使用 `api_access` 与 `network_access` capability，不新增 Admin API scope；需要 Shopify Plus Checkout Editor 才能在结账信息/配送/支付页面显示。

## 后端与数据检查

- 所有 Personalization 迁移批次已执行，且没有待迁移项。
- `personalization:reconcile-attribution`、`personalization:prune-data --due-only`、`personalization:prune-data` 出现在 Scheduler。
- 原始匿名事件/归因 90 天、聚合 13 个月、审计 365 天、在线清理不超过 72 小时、备份不超过 30 天。
- App Proxy 未签名返回 401；Webhook 错误 HMAC 返回 401；事件入口无效 source 返回 404。
- Checkout 配置入口无效或过期 Session Token 返回 401；只从签名 `dest` 解析店铺，不接受前端 Organization/Store 参数。
- 后台选择一个推荐策略并直接绑定到 Checkout；预设与自定义规则使用同一 Recommendation Strategy Service。Test Checkout 一次只显示一个商品，加入成功后重新计算并显示下一个，持续到没有合格候选。验证已在购物车、Market 不可售、首个可售变体、连续加购、失败重试、移动端与候选耗尽隐藏。
- Test Client ID/Secret、App Proxy Secret、Token 和 Cookie 不出现在日志与构建产物。

## 回滚

- 原生购物车推荐异常：先在 DecoAdmin 清除购物车策略选择，再关闭测试主题 App Embed；主题原生购物车抽屉始终保留。
- 推荐区块异常：从测试主题移除 App Block 或回退到测试主题修改前副本；不得修改已发布主题。
- Checkout 异常：在 DecoAdmin 将 Checkout 绑定切回已验证策略，或从 Checkout Editor 移除两个 App Block；扩展失败不得阻塞原生结账。
- Web Pixel 异常：在 Test App 版本回退/断开 Pixel，事件入口保持 fail-closed；不得删除其他 App 客户事件。
- App 版本异常：在 Dev Dashboard 只回退 `deco-personalization-test` 的上一个 Test 版本。
- DecoAdmin 异常：恢复上一组 Test App/Nginx 镜像；迁移均为新增表/列，除非已确认无数据，否则不执行破坏性 down migration，优先前向修复。
- 数据库：迁移前备份；恢复动作必须明确选择 Test 数据库，备份副本在 30 天内过期。

完成以上检查才可进入测试店安装、主题联调和端到端验收。任何范围偏差、权限增加、目标不确定或不可安全回滚异常都必须停止。

## 2026-08-29 Checkout Test 验收记录

- `deco-personalization-test-8` 已发布并替代第 7 版；版本地址：<https://dev.shopify.com/dashboard/74225522/apps/416463355905/versions/1108046610433>。两个 Checkout App Block 继续保存在 `macfox-test-app` 的活跃 Checkout 配置。
- 左侧信任信息在真实 Test Checkout 中成功读取后台配置并显示 `Trusted seller`、`Free US Shipping over $100`、`2-year warranty`；桌面与 375px 移动模拟均不阻塞原生结账。
- Session Token 使用官方文档定义的签名、`aud`、`dest` 与生命周期字段完成验证；Shopify 附加但未记录在 Checkout Token 契约中的 `iss` 不作为授权输入。
- 第 7 版曾使用当前 Test 配置中的 `3` 完成三款商品验收；该数值只代表当次测试商品数，不是固定商品槽位。新版后台允许商家填写可选的递进推荐数量上限；留空时继续遍历 Collection，填写时达到该数量后结束。同一区域仍一次只显示一个，加入成功后取得并显示下一个。
- 第 8 版真实 Test Checkout 的配置接口与 Storefront GraphQL 请求正常；本次三次递进加购产生的最近 `12` 个 Test 事件请求全部返回 `202`，没有非 `202` 响应，事件 source UUID 未输出。
- 经用户明确授权，`Macfox E-bike Storage Bag`、`Macfox E-bike Mobile Phone Holder`、`Macfox E-bike Rearview Mirror Kit` 在 `macfox-test-app` 的 `Shop location` 可用库存均设为 `10`，验收后保持 `10`，不恢复为 `0`。
- 第 8 版真实 E2E 从仅含 `Macfox X7` 的 Checkout 开始，按 Collection 默认顺序成功加入 Rearview Mirror Kit、Storage Bag、Mobile Phone Holder；每次都只有一个推荐卡片，加购成功后同一区域取得并显示下一项。当前 Collection 没有剩余合格商品后推荐区才隐藏，未循环、未重复，最终测试总额为 `USD $1,826.00`。
- 第 6 版复测发现候选切换时图片已更新但标题/价格残留上一项；提交 `fe7b0f4` 通过以 `variant_id` 为 key 重建候选行修复。第 7 版已确认第 2、3 项图片、标题、价格和实际加入商品一致，最终测试总额为 `USD $1,826.00`。
- 自动化验证覆盖超过三项的 `6` 商品递进选择以及 Collection 跨页继续获取；App 检查为 `30` 项通过，Personalization 后端测试为 `46` 项、`491` 个断言通过，DecoAdmin 前端生产构建通过。
- 不使用 Checkout DOM 修改、任意 HTML/CSS 注入、固定商品槽位或不准确的 Market 价格；Checkout 扩展加载失败或候选耗尽时仍保持原生结账可用。
