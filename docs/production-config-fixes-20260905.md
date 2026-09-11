# 正式环境配置与同步修复（2026-09-05）

目标：`https://admin.decomkt.com`，组织 1 / 店铺 3 Macfox Bike，Shopify `macfoxebike.myshopify.com`。测试为独立 `https://testadmin.decomkt.com`。

## 已修复并发布

- 折扣页优先使用本环境独立学生优惠 App 的现有授权，并通过其原有服务刷新到期令牌。不会把令牌写进 Commerce Hub 连接；新授权入口也不再向 Commerce Hub 追加折扣权限。保留已有合法旧授权的读取兼容。正式服务实际读取折扣成功，首批 50 条。
- SEO 搜索外观请求原来混用了 `date` 与 `searchAppearance`，Google 返回 400。现在先获取外观类型，再按类型过滤并读取日期，保留分页、店铺隔离与原子替换。依据：[Google 官方两步查询说明](https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data)。正式同步任务 9 已完成，处理 31,589 行，无错误；已写入 28 条搜索外观日记录。
- 测试服务器：折扣与自然流量 34 项测试、732 断言通过。测试服 Chrome 折扣列表正常。正式代码镜像 `decoadmin-app:config-fixes-20260905-production` / `decoadmin-nginx:config-fixes-20260905-production`，App、Nginx、Horizon、Scheduler 已切换，公网健康检查通过。没有更新未修改的 Shopify 扩展版本。

## 正式评价素材

- 从五款正式在售商品的公开 Shopify 商品图与官方详情图筛选 50 张，逐张通过联系表检查，按文件内容去重；没有新增或迁移 Demo 评论。
- X2 Pro 12 张，X1S 10 张，X1S x Bs.zay 5 张，X7 17 张，M16 6 张。包含商品、骑行与部分细节图；第三方 Loox 图片访问失败，没有使用失效链接。
- 使用现有素材服务建立五个文件夹、优化缩略图并记录审计。统一 Read more 为 `https://www.trustpilot.com/review/macfoxbike.com`，每次抽取 12 条，车型链接与当前店铺商品绑定。
- 50 张缩略图总计 2,709,098 字节，平均 54,181 字节。原图约 19.62 MiB 留在素材库；店铺展示使用缩略图和已发布的接近视口加载逻辑。
- 正式 Community Reviews App 配置 CLI 验证通过；Dev Dashboard 确认版本 `community-reviews-production-20260905` 有效，Client ID `3c5fcae3d492476532735aa2f4090f98`，应用及代理均指向 `admin.decomkt.com`。
- Shopify 安装选择页已找到正式 Macfox Bike。继续点击时电脑锁屏，已请求用户解锁。尚未完成该店铺应用安装、会话连接或主题展示核验。

## Meta 恢复

- 失败位于任务 11527 的一个广告组层级报表，2026-09-02 至 2026-09-04。其他 47 个分片已完成。手动同步会恢复同一任务，因此不能只按新任务编号判断是否执行。
- 当前没有 Token 失效证据。使用现有最小拆分窗口配置，将失败的多日报表拆成单日任务；保留单日重试次数上限和已完成数据。
- 测试服务器三个相关回归检查共 20 断言通过，包括三天拆分、较大范围拆分和单日失败停止。
- 测试及正式 `META_ADS_ASYNC_MIN_WINDOW_DAYS=1` 均已加载。原失败分片 8077 被替换为 8137/8138/8139，分别补回 46/39/41 条记录；任务 11527 于 2026-09-05 08:28:33 UTC 完成，错误码为空，页面状态服务返回 completed。
- 当前素材列表的 59 条预览地址经原 Meta 授权重新获取并更新，没有 API 错误；之前返回 403 的两条样本均恢复为 HTTP 200。刷新的是有期限的 Meta CDN 地址，未新增长期图片缓存或自动更新机制。
- 本轮共 37 项相关检查、752 断言通过。

## 备份

本轮源码与 Meta 参数调整前的环境备份在各环境 `.releases/config-fixes-20260905/`。已加入原发布的 29 天清理规则，仅匹配 `source-before.tar.gz` 和 `env-before-meta`。环境备份权限为 600。
