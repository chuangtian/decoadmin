# 2026-09-05 正式发布记录

用户明确授权全部后台修改及 Community Reviews、Deco 个性化推荐正式版本发布。

- 正式目录：`/opt/decoadmin/production`；环境文件：`.env.production`；Compose：`decoadmin-production`。
- 正式源站：`https://admin.decomkt.com`；HTTP 内部端口：18081。
- 最终镜像：`decoadmin-app:20260905-community-personalization-r2` / `decoadmin-nginx:20260905-community-personalization-r2`。
- 原正式镜像：`decoadmin-app:8bfbf16-production` / `decoadmin-nginx:8bfbf16-production`。
- 发布候选位于本机 `tmp/production-release-20260905`，由本地改动和正式基线 `8bfbf16` 基于共同祖先 `c01ae633` 合并。保留正式服已有 Codex 授权管理、感谢页和订单状态页推荐等功能；合并近期折扣管理、折扣监控、店铺时间和报表修改。

## Shopify 正式版本

| App | 独立正式 Client ID | 正式版本 |
| --- | --- | --- |
| Community Reviews | `3c5fcae3d492476532735aa2f4090f98` | [community-reviews-production-20260905](https://dev.shopify.com/dashboard/74225522/apps/419449700353/versions/1116678750209) |
| Deco 个性化推荐 | `b4dca5d161757cb26e0e805642a3b775` | [deco-personalization-production-20260905](https://dev.shopify.com/dashboard/74225522/apps/417108983809/versions/1116679798785) |

两个 App 的回调、Webhook、代理和应用入口都使用正式源站。Secret 只存独立环境文件。未运行测试数据脚本，正式数据库中的 Community Reviews 示例评论数量为 0。本次不指定店铺安装，不修改店铺主题、购物车启用状态或永久禁止目标的配置。

## 验证和修复

- 测试服务器隔离容器执行 595 项后端检查；初次完整资源回归 593 项通过，余下 2 项依赖 Redis 和队列重试参数。配置独立临时 Redis 和 1860 秒重试窗口后，相关 25 项检查、137 条断言通过；临时容器和网络已清理。
- 个性化推荐合并版：49 项扩展检查通过，App Home 和 Checkout 类型检查通过；两 App 官方正式配置校验和构建完成，版本发布成功。
- 回归修复：自动保存的幂等签名改为基于客户端请求，防止规范化时新增的选择时间造成跨秒重试冲突；回归测试增加 2 秒时间推进。
- 个性化推荐 Theme Check 仍提示两个脚本超出其 10KB 建议阈值（14,867 / 39,541 字节）；官方最终版本校验和发布成功。未为压过阈值而关闭校验。
- 首次切换因新建源码目录权限过严出现 HTTP 500，回退旧 HTTP 镜像并清理可重建的配置/服务缓存后恢复。最终构建显式赋予源码目录 Web 进程读取权限，并以 `www-data` 验证启动；再切换 r2。
- 最终正式源站 `/health` 返回 200，应用、MySQL 和 Redis 正常；工作台可在 Chrome 打开。Personalization Production release-check 通过，新表与相关前端资源完整，Community Reviews 未签名代理请求返回 401。

## 备份与回滚

数据库、源码、原环境和镜像记录保存在 `/opt/decoadmin/production/.releases/20260905-community-personalization/`，权限受限。迁移为新增表/字段及权限配置；回滚优先切回旧镜像，不回滚业务数据。跨含 CommunityReviews Provider 的版本回滚时必须清理或重新生成配置、服务和路由缓存。

用户于 2026-09-05 明确授权本次发布备份在 29 天后自动删除。已安装 `/etc/cron.d/decoadmin-release-20260905`，每小时第 17 分钟仅清理本发布目录中超过 41,760 分钟的 `database-before.sql.gz`、`source-before.tar.gz`、`env-before` 和 `app-production.env`。cron 服务已确认 active；安装时没有到期文件。

## 同日页面检查后续更新

正式菜单页与主要标签检查完成后，修复报表后台加载后的页面自动更新、SEO 空数据误判、同步/Webhook 时区字段与按行显示、UTC 选项、应用导航和文字排版。26 项后端回归、304 条断言与 5 项前端自动加载检查在测试服务器通过，macfox-test-app 浏览器复查通过后发布。

当前正式镜像为 `decoadmin-app:page-audit-20260905` / `decoadmin-nginx:page-audit-20260905`，测试最终镜像为对应 `page-audit-20260905-r2`。正式应用、数据库、Redis、公网健康检查均正常，队列与调度器完成切换。回滚目标是本次更新前的 `20260905-community-personalization-r2`。Shopify 两 App 正式域名、ID 和已发布版本保持独立且已核验，正式 Demo 评论数量为 0。

详见 [逐页检查记录](production-page-audit-20260905.md)。
