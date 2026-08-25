# Deco 学生优惠 Shopify App

Shopify Plus 组织内部使用的学生优惠应用。业务配置、学生证审核、邮件和优惠码发放均由 DecoAdmin 后台负责；本目录只包含 Shopify App Home 与 Theme App Extension。

## 环境

| 环境 | Shopify App | 配置文件 | DecoAdmin 域名 | App Proxy |
| --- | --- | --- | --- | --- |
| Local | `Deco-学生优惠-local` | `shopify.app.local.toml` | 当前 Cloudflare 临时域名 | `/apps/deco-student-local` |
| Test | `Deco-学生优惠-test` | `shopify.app.test.toml` | `https://testadmin.decomkt.com` | `/apps/deco-student-test` |
| Production | `Deco-学生优惠` | `shopify.app.production.toml` | `https://admin.decomkt.com` | `/apps/deco-student-discount` |

默认 `shopify.app.toml` 与 Local 配置保持一致。Local 临时域名变化时，需要同步更新其中的 App URL、Webhook、OAuth 回调和 App Proxy 目标 URL，再发布 Local App 配置。

三个环境使用独立 Shopify App、数据库、Redis、学生证存储和邮件服务。不要把任何 API Key、Client Secret 或学生信息提交到仓库。

## 功能边界

- App Home 仅显示连接状态、DecoAdmin 管理入口和“添加到主题”入口。
- 商家在 DecoAdmin 中单独登录并管理当前店铺的活动配置和审核。
- Theme App Extension 只保留显示开关、文字/图片、对齐、宽度、颜色和圆角设置。
- 店面请求通过 Shopify App Proxy 进入 DecoAdmin；不能信任浏览器传入的店铺 ID。
- 主题扩展从 AppInstallation app-data metafield `deco_student_discount.proxy_path` 读取当前环境的代理路径。

## 本地开发

从 DecoAdmin 仓库进入独立 App 目录：

```shell
cd shopify-apps/student-discount
```

```shell
npm install
npm run dev:test-store
```

默认开发店铺是 `macfoxebike.myshopify.com`。需要切换时：

```shell
DECO_STUDENT_STORE=example.myshopify.com npm run dev:test-store
```

仅预览店面弹窗，不连接 Shopify：

```shell
npm run dev:local-preview
```

## 校验

```shell
npm run check
```

也可以按环境单独构建：

```shell
npm run build:local
npm run build:test
npm run build:production
```

构建和校验不会发布。只有明确授权后才能运行 `shopify app deploy` 或在 Dev Dashboard 创建/发布版本。

获得明确授权后，也必须使用目标明确的环境命令：

```shell
npm run deploy:local
npm run deploy:test
npm run deploy:production
```
