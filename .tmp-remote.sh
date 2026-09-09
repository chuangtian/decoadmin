#!/usr/bin/env bash
set -uo pipefail
cd /opt/decoadmin/staging || exit 1
compose=(docker compose --env-file .env.staging -f compose.production.yaml)

echo "=== store 2 的主 App 连接（synchronizeInstallation 的前置条件） ==="
"${compose[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -B "$MYSQL_DATABASE" -e "
SELECT id, store_id, shop_domain, status, installed_at, uninstalled_at, deleted_at
FROM shopify_connections WHERE store_id = 2"' < /dev/null 2>&1 | grep -v '^mysql:'

echo "=== app_installations 里 IG App(4) 的记录 ==="
"${compose[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -B "$MYSQL_DATABASE" -e "
SELECT id, app_id, store_id, shopify_connection_id, status, external_installation_id, installed_at, uninstalled_at, deleted_at
FROM app_installations WHERE app_id = 4"' < /dev/null 2>&1 | grep -v '^mysql:'

echo "=== 复合外键目标是否匹配 ==="
"${compose[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -B "$MYSQL_DATABASE" -e "
SELECT CONCAT(\"connection_for_store_2=\", IFNULL((SELECT id FROM shopify_connections WHERE store_id = 2 AND deleted_at IS NULL LIMIT 1), \"none\"))"' < /dev/null 2>&1 | grep -v '^mysql:'
