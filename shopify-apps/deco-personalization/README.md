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

## 当前状态

阶段 2 只建立安全的 Test-first 工程骨架。Test Client ID 和实际 Shopify 配置将在只读定位现有 Test App 后补齐；Client Secret 只进入批准的 Secret/环境配置，不进入本目录。

## 离线校验

```shell
npm run check
```

该命令不连接 Shopify，不安装 App，也不修改任何环境。
