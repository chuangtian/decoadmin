#!/usr/bin/env bash
set -uo pipefail
echo "=== RELEASE / images ==="
cat /opt/decoadmin/staging/RELEASE
grep -E '^(APP_IMAGE|NGINX_IMAGE)=' /opt/decoadmin/staging/.env.staging
echo "=== containers ==="
docker ps --filter name=decoadmin-staging --format '{{.Names}}\t{{.Image}}\t{{.Status}}' | sort
cd /opt/decoadmin/staging || exit 1
compose=(docker compose --env-file .env.staging -f compose.production.yaml)
echo "=== 迁移是否已应用 ==="
"${compose[@]}" exec -T app php artisan migrate:status --no-ansi < /dev/null 2>&1 | grep -i 'instagram_feed_installations' || echo "未找到该迁移"
echo "=== 新字段是否落库 ==="
"${compose[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B "$MYSQL_DATABASE" -e "SHOW COLUMNS FROM instagram_feed_installations" ' < /dev/null 2>&1 | grep -v '^mysql:' | awk '{print $1}' | tr '\n' ' '
echo
echo "=== 授权相关路由 ==="
"${compose[@]}" exec -T app php artisan route:list --no-ansi --name=instagram-feed.shopify-oauth < /dev/null 2>&1 | tail -5
echo "=== 回调端点对外可达性（未带 state 应为 4xx，不是 404） ==="
curl -s -o /dev/null -w 'oauth_callback=%{http_code}\n' --max-time 20 'https://testadmin.decomkt.com/shopify-app/instagram-feed/oauth/callback'
curl -s -o /dev/null -w 'health=%{http_code}\n' --max-time 20 https://testadmin.decomkt.com/health
echo "=== 现有安装记录 ==="
"${compose[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B "$MYSQL_DATABASE" -e "SELECT CONCAT(\"rows=\", COUNT(*)) FROM instagram_feed_installations" ' < /dev/null 2>&1 | grep -v '^mysql:'
