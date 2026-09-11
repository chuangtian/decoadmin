# Community Reviews

多店铺买家秀评价 Shopify App。代码、配置、扩展、构建依赖和后端模块均归本目录所有。

- 评论来自 DecoAdmin `reputation_mentions`，只使用当前组织、店铺中启用的四星和五星评论。
- 车型素材来自 `model_asset_folders` / `model_asset_images`。每个文件夹关联本店一个商品，可以配置车型名称、系列和识别别名。
- 优先匹配评论中的车型；明确车型没有素材、没有关联或已经停售时跳过。没有车型的评论随机抽取符合条件的图片，再跟随其文件夹确定商品。
- 在售要求：本地商品处于 active，并核对 Shopify ACTIVE 和 Online Store 发布状态；状态最多缓存 30 秒，收到商品更新/删除通知立即失效。状态缓存到期后核对失败则关闭展示，不使用过期状态。
- Read more 每店统一配置一个 HTTPS 地址，留空时隐藏。View Bike 始终使用配图对应商品的详情页。
- 无箭头、无分页的循环轮播，支持鼠标拖动、触屏、水平触控板、键盘，支持惯性和减少动画偏好。缓慢自动滚动为可选项，默认关闭。
- 上方图片为商品素材配图，不声明为该评论作者上传。

## 加载、缓存与去重

- 店铺前台使用 IntersectionObserver，在区块进入视口外 400px 范围内才请求评价；主题编辑器立即加载以便配置。预留区块高度，避免延迟加载导致页面跳动。
- 评论、素材及配置候选缓存 300 秒；组织、店铺和环境共同构成缓存键。缓存只保存展示所需的数据，不保存评论的私密来源字段、凭据或订单资料。
- 通过提交后的模型事件和批量操作审计信号使缓存失效；缓存代际标识避免并发刷新重新写回失效的数据。候选池和 Shopify 状态刷新使用独立互斥锁。
- 每批最多 24 张卡片，评论按规范化正文、图片按缩略图内容指纹去重；候选不足时减少卡片，不重复填充。图库采用最多 5000 张图片、1000 条评论的随机候选窗口，每 5 分钟轮换，避免无界载入。
- 同一浏览器使用每店独立的匿名随机标识，服务端保留 24 小时、最多各 2000 条评论/图片指纹的近期展示历史；刷新优先未展示内容，其次使用最早展示的内容。此标识不关联账号、IP 或浏览器信息；浏览器禁用存储时仍保证单批去重。
- 足够内容时无缝循环；内容少于视口时不复制卡片凑数，略超出视口时允许有限拖动。没有左右按钮。
- 主图继续使用 640px WebP 缩略图，URL 随新图片 UUID 改变，浏览器缓存 86400 秒。底部 Shopify 车型小图请求 180×180 以内的版本，保留原图。格式转换采用 [Shopify Image transform](https://shopify.dev/docs/api/admin-graphql/latest/objects/Image) 的 best-effort 行为。

## 性能更新测试发布（2026-09-05）

- Shopify 测试版本：`community-reviews-performance-20260905`，版本 ID `1116351168513`。
- 测试服务器镜像：`decoadmin-app:community-reviews-20260905-performance` / `decoadmin-nginx:community-reviews-20260905-performance`；源码和环境备份在 `/opt/decoadmin/staging/.releases/community-reviews-20260905-performance/`。
- 当前测试店数据：4 款在售车型、各 15 张素材，共 60 张；8 条明确标注 Demo 的四星/五星示例评论。
- 测试服务器隔离容器：7 项测试、42 条断言通过，覆盖缓存 TTL、跨店隔离、评论及图片内容去重、连续刷新和商品通知失效。
- 实际签名 HTTP 连续三次每次 8 张卡片，合计 24 张不同图片；单批评论/图片均无重复，图片响应缓存为 86400 秒，非法访客标识返回 422。
- 测试服访问公开后端接口的三次样本：冷缓存 951ms，热缓存 273/274ms；不包含顾客网络或 Shopify Proxy 耗时，不能作为全店页面速度评分。四款商品小图合计从约 1.1MB 降到约 128KB。
- Chrome 实际测试店页面：顶部尚无评价数据，滚动接近区块后展示；无箭头轮播拖动正常，180px 车型图在卡片内清晰。
- View Bike 使用纯文字链接。鼠标按下会取消键盘焦点跟随，避免链接获得焦点时轮播先移动，确保点击后进入当时所见卡片对应的车型详情页。
- 点击修复测试版本：`community-reviews-click-fix-20260905`，版本 ID `1116363390977`。录屏问题复现后，在测试店实际点击 X2 Pro 的 View Bike，页面直接进入 `/products/macfox-x2`，轮播没有先移动。

## 后台集成

根项目只增加 Composer namespace、Provider、前端页面解析及菜单注册。Laravel 业务代码、路由、配置、迁移和 Vue 页面保留在本目录。管理入口：

`/organizations/{organization}/stores/{store}/community-reviews`

读取要求 `products.view` + `reports.view`，修改另要求 `products.update`；服务层校验用户、组织、店铺。对外 App Proxy 验证本 App 的签名并解析店铺，凭据不进入接口结果或日志。

## 环境

- test：`https://testadmin.decomkt.com`，仅 `macfox-test-app.myshopify.com` 用于本次联调。
- production：`https://admin.decomkt.com`，独立正式 App client ID `3c5fcae3d492476532735aa2f4090f98`；local 配置仍为占位。不得复用 test 凭据。
- 环境变量：`COMMUNITY_REVIEWS_ENVIRONMENT=test`、`COMMUNITY_REVIEWS_TEST_CLIENT_ID`、`COMMUNITY_REVIEWS_TEST_CLIENT_SECRET`。Secret 仅放未跟踪环境文件。

## 构建和发布

```sh
npm ci
npm run build:assets
shopify app config validate --config test --json
shopify app build --config test
```

先构建并部署 DecoAdmin 测试后端及数据库迁移，确认新路由已可用，再执行：

```sh
shopify app deploy --config test
```

在 Shopify 安装并打开应用完成连接。在 DecoAdmin 配置素材与商品、统一链接并启用。最后在测试店铺主题编辑器添加 Community Reviews App 区块。

用户要求功能测试只在测试环境执行；本地仅开发、静态校验和构建。

## 正式发布（2026-09-05）

- 独立正式版本：[community-reviews-production-20260905](https://dev.shopify.com/dashboard/74225522/apps/419449700353/versions/1116678750209)。
- 端点全部指向 `admin.decomkt.com`，正式环境使用 `COMMUNITY_REVIEWS_ENVIRONMENT=production` 和独立正式凭据。
- 正式后台和 Shopify App 版本已发布；店铺安装、车型配置和主题添加须选择具体店铺。本次没有迁移测试评论或素材。
- 合并发布、健康验证及回滚记录见 `docs/production-release-20260905.md`。

## 本次测试发布记录（2026-09-04）

- Shopify 测试版本：`community-reviews-test-20260904`，独立 App client ID：`486e97d18c4153e6b06ccb8f5d5c62f8`。
- 已安装到 `macfox-test-app.myshopify.com`，并通过 App Bridge 与 DecoAdmin 测试服建立本 App 会话。
- 测试配置：X1S、X2 Pro、M16、X7；每车型 3 张该测试店 Shopify 商品图片。13 条标注 TEST 的测试记录中，11 条可展示、1 条三星及 1 条未知车型用于验证过滤。
- 当前测试主题 `Deco Smart Cart Test`（165279695096）的默认产品模板已添加并保存区块，可通过 `/products/hub-cover` 查看。测试主题首页原有 404，未修改其首页逻辑。
- 测试服务器隔离容器执行 PHPUnit：3 项测试、20 条断言通过；使用内存数据库，未在本地运行功能测试。
- 实际 HTTP：已签名请求返回 11 张卡片，未签名请求返回 401；实际素材、评论车型与商品链接无错配。
- Shopify 主题编辑器完成多次左右拖动、首尾循环、手机尺寸布局检查；View Bike 已实际跳转到 M16 详情页。
- 测试服备份目录：`/opt/decoadmin/staging/.releases/community-reviews-20260904/`。包含部署前源码、环境文件和数据库备份；该目录排除在 Docker 构建上下文外。
