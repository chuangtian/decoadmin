# 费用申请日期临时放开

2026-09-15：按用户要求，暂时允许所有具有费用申请提交权限的员工填写过去、今天或未来的计划使用日期。日期仍然必填，且必须是有效的 `YYYY-MM-DD` 日期。申请人权限、组织隔离、审批和实际创建时间保持原有逻辑。

配置：`config/expense_requests.php` 中 `allow_past_dates` 默认开启，由 `EXPENSE_REQUESTS_ALLOW_PAST_DATES` 控制。前端从后端读取实际开关状态。

历史补录结束后，在目标环境的环境文件中设置：

```dotenv
EXPENSE_REQUESTS_ALLOW_PAST_DATES=false
```

按该环境发布流程重新生成 Laravel 配置缓存；若环境变量由 Compose 注入，需先重新创建相应服务以加载新值。普通员工与管理员的新申请都恢复“今天或以后”的校验；既有历史记录不会重写或删除。

费用申请提交失败使用中文错误弹窗，保留已填写内容。回归测试 `PersonalRequestWorkflowTest` 覆盖临时开放、关闭后恢复、无效日期、跨组织代填拒绝以及真实创建时间和代填审计记录。
