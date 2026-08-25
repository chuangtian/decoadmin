# 学生优惠后台最终验证

- 验证时间：2026-08-26（Asia/Shanghai）
- 验证范围：DecoAdmin 学生优惠后台实现
- 执行环境：项目既有 Docker Compose `app` / `vite` 服务
- 结论：通过；发布前补强环境隔离、AI 自动通过条件及幂等内容指纹；Shopify App 安装不在本轮范围

## 专项测试

执行：

```text
docker compose exec -T app php artisan test tests/Feature/StudentDiscountTest.php
```

结果：`12 passed`，`124 assertions`，耗时约 `1.02s`。

覆盖内容包括：

- Gemini API Key 加密存储且不向前端回显；
- 非超级管理员无法读取或修改 AI 设置；
- 教育邮箱快速通过、优惠码创建及幂等复用；
- 低置信度申请进入人工审核而非自动拒绝；
- App Proxy 无效签名拒绝及审核完成 30 天后的证件清理；
- Organization、Store 和 RBAC 审核范围隔离；
- Shopify OIDC claims 校验及 bootstrap metafield 写入；
- token exchange 与 AppInstallation 缺少必要 scopes 时拒绝；
- OIDC audience 或 shop 不匹配时拒绝。
- `APP_ENV=staging` 自动选择测试 App 配置，不会回退到本地 App；
- Gemini 明确判定为非学生证时，即使置信度很高也不会自动通过；
- 同尺寸、同 MIME 但内容不同的证件不会复用同一幂等结果。

## PHP 语法、格式与静态检查

项目未配置 PHPStan 或 Larastan，因此按现有 PHP 工具链执行以下检查：

1. 对本次变更涉及的全部 `34` 个 PHP 文件逐个运行 `php -l`：全部通过，无语法错误。
2. `docker compose exec -T app vendor/bin/pint --test`：通过，检查 `548 files`。
3. `docker compose exec -T app composer validate --strict --no-check-publish`：通过，`composer.json is valid`。
4. `git diff --check`：通过，无空白符错误。

## 前端检查与构建

执行：

```text
docker compose exec -T vite npm run type-check
docker compose exec -T vite npm run build
```

结果：

- Vue TypeScript `vue-tsc --noEmit`：通过；
- Vite production build：通过；
- Vite `8.2.1`，转换 `909 modules`，构建耗时约 `3.40s`；
- `public/build/manifest.json` 已生成并可解析；
- `resources/js/Pages/StudentDiscounts/Index.vue` 对应构建资源存在；
- `resources/js/Pages/System/Settings.vue` 对应构建资源存在。

构建仅出现现有 `Live` 页面 chunk 大于 500 kB 的非阻断警告，与学生优惠页面无关。

## Vite 运行状态

production build 后已执行：

```text
docker compose restart vite
```

最终复核：

- `vite` 容器状态：运行中；
- Vite：`ready in 440 ms`；
- `public/hot`：`http://localhost:5173`；
- Inertia SSR module graph：已完成预热。

重启后的第一次并行探测发生在 `public/hot` 尚未重新写入的短暂启动窗口，因此该次探测返回非零；随后复核全部通过，不属于代码或构建失败。

## 数据库与路由只读核验

- `2026_08_25_020000_create_student_discount_tables`：`Ran`；
- `2026_08_25_021000_create_student_discount_claim_idempotencies_table`：`Ran`；
- 学生优惠相关后台、App Home 和 App Proxy 路由：已注册；
- AI 学生证设置保存路由：已注册。

## 完整回归

- `docker compose exec -T app php artisan test --compact --colors=never`：`371 passed`，`6443 assertions`；
- 未安装 Shopify App，未调用真实 Shopify、Gemini 或邮件外部服务；
- 未输出、验证或记录任何 Secret 明文。

## 本地 Gemini 配置落库验证

已通过 `system:configure-student-ai` 的隐藏交互输入，在当前 DecoAdmin 本地环境数据库中安全保存 `student_ai` 配置。命令参数、终端输出和本文档均未包含 Secret 明文。

限定验证结果：

- `gemini_api_key_configured=true`；
- `gemini_model=gemini-2.5-pro`；
- `auto_approval_threshold=80`；
- 数据库原始 `value` 不包含解密后的明文：`true`。

本次操作仅修改当前本地环境数据库和本验证文档，未发布或部署。
