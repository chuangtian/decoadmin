# 2026-09-07 测试服全部后台修改同步正式服

- 用户授权：将测试服当前全部后台修改更新到正式服；不发布 Shopify App，不复制测试数据或测试环境凭据。
- 测试服遗漏代码已补交：`eb853f7`，包含红人记录隐藏/回收站及店铺隔离、新增状态表、EDM 趋势与自然流量页面调整。
- 正式代码合并：`110c8da`，与 `eb853f7` 内容一致，保留 `main` 历史。
- 正式目录 `/opt/decoadmin/production`，环境 `.env.production`，Compose `decoadmin-production`；测试目录及 `.env.staging` 不混用。
- 正式镜像：`decoadmin-app:110c8da-test-all-production`、`decoadmin-nginx:110c8da-test-all-production`。
- 发布仅更新后台代码与构建资源；保留正式 `shopify-apps/` 文件和正式环境配置。未执行 Shopify 发布、安装、主题、折扣或授权变更。

## 验证

- 本地完整后端回归 595 项通过（9468 条断言）；追加红人记录权限、跨店铺隔离及原始数据保留测试后，自然流量专项 24 项通过（597 条断言）。
- 本地和服务器前端构建成功，本地 Vite 已重启并就绪。
- 仅执行新增迁移 `2026_09_07_180000_create_influencer_record_states_table`，正式批次 25；没有回填测试记录或运行全量 Seeder。
- 正式 `/health`、`/login` 和新 CSS 资源返回 200，应用、数据库、Redis、Nginx 健康，Horizon 和 Scheduler 已切换新镜像。
- 测试服 `/health` 仍正常。

## 备份及回滚

备份目录：`/opt/decoadmin/production/.releases/test-all-110c8da-20260907`。
数据库压缩备份已通过完整性检查；目录另含发布前源码、受限权限环境文件、原 RELEASE 与镜像清单。不得提交或输出备份中的凭据和业务数据。

原应用镜像为 `decoadmin-app:google-data-fixes-20260907-production`，原 Nginx 为 `decoadmin-nginx:app-center-permission-20260907-production`。需要回滚时优先恢复这些镜像与匹配缓存，保留新增表及业务数据，不直接执行迁移回滚。此次未设置备份自动删除。
