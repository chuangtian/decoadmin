# 重点产品监控（本地）

入口：业务中心 → 商品管理 → 重点监控。仅手动标记的产品参与检查，标记不会改变 Shopify 数据。管理入口使用当前店铺的 `products.update` 权限，查看使用 `products.view`。

- 每 5 分钟扫描一次。以店铺 + Shopify 产品 ID 定位，库存按变体 ID + 地点 ID 比较，名称变更不丢失对象。
- 库存区间：≤0 缺货、0 至阈值（含阈值）为低库存，其余正常；默认阈值 10，可在标记前修改，0 表示只关注缺货。
- 仅区间变化提醒，不为同一区间的每次销量变化发消息；恢复后再次缺货会生成新的提醒。
- 产品状态双向变化、在线商店发布/取消发布、产品找不到/恢复、已有变体移除、库存跟踪切换及库存地点移除/停用会提醒。
- 第一次成功读取、重新开启及调整阈值后建立安静基线；新出现的变体/地点首次读取也建立基线。已处于缺货状态的基线不会立即告警。
- 不跟踪库存的变体不作为零库存；不完整分页、权限失败及 API 错误不会覆盖成功快照。页面展示安全错误信息及最近成功检查时间。
- 取消后停止扫描，尚未投递的旧一轮通知会跳过；已经投递的通知不能撤回。
- 通知沿用当前店铺飞书/邮箱配置，新增「重点产品库存与状态预警」开关。完整变更内容保存在告警记录中。

本地模拟测试不会调用真实 Shopify 或飞书。手动验收请只在已授权的 `macfox-test-app` 标记产品，等待首轮基线成功后再改变测试店商品；不要操作其他店铺。

必要权限：`read_products`、`read_inventory`、`read_locations`（同类 write 权限也满足读取）。本功能不会自动申请或扩大权限。

部署需执行 `2026_09_07_220000_create_shopify_product_monitors` 迁移、构建前端并更新应用/队列/调度服务。本次仅本地开发，不代表已部署测试服或正式服。

诊断命令（只读 Shopify，但可能发通知）：`php artisan shopify:monitor-products --store=<本地店铺ID>`。不要为验收扫描未授权店铺。

只读查询已用 Shopify 2026-07 schema 校验：
- [Product](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Product)
- [InventoryItem](https://shopify.dev/docs/api/admin-graphql/2026-07/queries/inventoryItem)
- [InventoryLevel](https://shopify.dev/docs/api/admin-graphql/2026-07/queries/inventoryLevel)
