# Deco 个性化推荐 Test 发布清单

本清单只适用于 `Deco 个性化推荐测试` / `deco-personalization-test`。Test 是第一个真实运行环境；本机仅运行代码、Docker 测试和离线构建。不得创建或运行 Local Shopify App，不得使用 Cloudflare 临时域名，不得执行任何 Production 操作。

## 固定目标与停止条件

- Dev Dashboard 组织必须是 `E-LINK TECHNOLOGY CO LTD`。
- 外部联调店必须解析为 `macfox-test-app.myshopify.com`。
- `Macfox Bike` / `macfoxebike.myshopify.com` 是永久禁止目标；任何解析结果为该店或目标不确定时立即停止。
- Test App 必须复用用户已创建的现有 App，不得重复创建。
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
10. 只在未发布测试主题副本添加推荐 App Block、启用 Smart Cart App Embed，并完成 Web Pixel 与端到端验收。

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

包装器只允许 `validate`、`build`、`deploy` 三种 Test 动作；店铺、组织、确认值不匹配或命中 denylist 会在调用 Shopify CLI 前失败。

## Test 配置合同

- 显示名称：`Deco 个性化推荐测试`；handle：`deco-personalization-test`。
- Application URL：`https://testadmin.decomkt.com/shopify-app/personalization`。
- 最小 scopes 仅为 `write_app_proxy`、`write_pixels`、`read_customer_events`。
- App Proxy：`/apps/deco-personalization-test` 指向 Test 后端 Personalization 代理入口。
- App-specific Webhook：`app/uninstalled`、`app/scopes_update`。
- 合规 Webhook：`customers/data_request`、`customers/redact`、`shop/redact`。
- `embedded = true`、Shopify managed install、禁止 CLI 自动改写 URL。
- Theme App Extension 不读取或写入主题文件，因此不申请 `read_themes` / `write_themes`。

## 后端与数据检查

- 所有 Personalization 迁移批次已执行，且没有待迁移项。
- `personalization:reconcile-attribution`、`personalization:prune-data --due-only`、`personalization:prune-data` 出现在 Scheduler。
- 原始匿名事件/归因 90 天、聚合 13 个月、审计 365 天、在线清理不超过 72 小时、备份不超过 30 天。
- App Proxy 未签名返回 401；Webhook 错误 HMAC 返回 401；事件入口无效 source 返回 404。
- Test Client ID/Secret、App Proxy Secret、Token 和 Cookie 不出现在日志与构建产物。

## 回滚

- Smart Cart 异常：先在 DecoAdmin 一键恢复 Shopify 默认购物车，再关闭测试主题 App Embed。
- 推荐区块异常：从测试主题移除 App Block 或回退到测试主题修改前副本；不得修改已发布主题。
- Web Pixel 异常：在 Test App 版本回退/断开 Pixel，事件入口保持 fail-closed；不得删除其他 App 客户事件。
- App 版本异常：在 Dev Dashboard 只回退 `deco-personalization-test` 的上一个 Test 版本。
- DecoAdmin 异常：恢复上一组 Test App/Nginx 镜像；迁移均为新增表/列，除非已确认无数据，否则不执行破坏性 down migration，优先前向修复。
- 数据库：迁移前备份；恢复动作必须明确选择 Test 数据库，备份副本在 30 天内过期。

完成以上检查才可进入测试店安装、主题联调和端到端验收。任何范围偏差、权限增加、目标不确定或不可安全回滚异常都必须停止。
