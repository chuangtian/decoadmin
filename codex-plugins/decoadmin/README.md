# DecoAdmin Codex Plugin

DecoAdmin 的内部 Codex 插件。它通过本地 MCP 适配器调用 DecoAdmin 的受控 API，不直接访问数据库，也不包含任何环境密钥。

## 当前能力

- 列出当前账号有权查看的店铺
- 查询经营概览和趋势
- 分页查询不含顾客联系方式的订单信息
- 查询同步、Webhook 和运行记录摘要
- 检查配置完整性，但不返回配置值或密钥
- 查询系统、队列和调度器健康状态
- 二次确认后刷新经营分析缓存
- 二次确认后发起或重试 Shopify 数据同步
- 二次确认后修改非密钥通知开关
- 二次确认后开启或关闭学生优惠活动

所有写操作都会在准备和执行阶段分别检查令牌能力、用户、组织、店铺和 RBAC 权限。确认单 10 分钟过期，重复执行返回原结果，不会重复写入，并记录审计日志。

## 后台授权

组织管理员可在 DecoAdmin 打开「系统管理 → Codex 插件授权」，为当前组织成员选择只读或写入能力、有效期并签发令牌。签发和撤销均需二次确认，写入审计日志且支持幂等重试。令牌明文只显示一次。

用户的后台角色、店铺成员范围和令牌能力会同时生效；任何一层不允许，插件都不会执行。

## 命令行备用方式

先在目标环境执行数据库迁移，然后为指定用户和组织签发短期只读令牌：

```bash
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan codex:issue-token admin@example.com decomkt --expires=30
```

令牌只显示一次。不要把令牌写入仓库、截图、日志或聊天内容。

默认令牌仍是只读的。需要第二版写入能力时必须显式签发，并且后台 RBAC 仍会限制每个用户实际可以执行的动作：

```bash
docker compose exec -T app php artisan codex:issue-token admin@example.com decomkt \
  --abilities=stores:read,dashboard:read,orders:read,operations:read,configuration:read,system:read,analytics:write,sync:write,configuration:write \
  --expires=30
```

不再使用时立即撤销：

```bash
docker compose exec -T app php artisan codex:revoke-token 令牌UUID
```

## 插件配置

插件默认从 `~/.agents/plugins/decoadmin.local.json` 读取当前选中环境的地址和令牌。使用配置脚本时，令牌通过标准输入传入，不会显示在命令参数中。Mac 用户可在后台复制令牌后执行：

```bash
pbpaste | node ~/plugins/decoadmin/scripts/configure.mjs --base-url https://testadmin.decomkt.com
```

切换回本地开发时，使用本地单独签发的令牌：

```bash
pbpaste | node ~/plugins/decoadmin/scripts/configure.mjs --base-url http://127.0.0.1:8000
```

配置文件权限必须为 `600`。也可以在临时调试时使用 `DECOADMIN_BASE_URL` 和 `DECOADMIN_API_TOKEN` 环境变量；两者会优先于配置文件。

三个环境必须分别签发令牌并分别配置：

- 本地开发：`http://127.0.0.1:8000`（仅限本机回环访问，不依赖临时域名）
- 测试服：`https://testadmin.decomkt.com`
- 正式服：`https://admin.decomkt.com`

不要使用同一个配置同时指向多个环境，也不要把测试令牌用于正式服。

## 本地验证

```bash
npm test --prefix codex-plugins/decoadmin
python3 /Users/tianchuang/.codex/skills/.system/plugin-creator/scripts/validate_plugin.py codex-plugins/decoadmin
```

插件不允许写入 SMTP 密码、API Key、Token、飞书 Webhook 等密钥，也不提供审核申请、删除数据或部署能力；这些操作仍需在 DecoAdmin 或受控发布流程中完成。
