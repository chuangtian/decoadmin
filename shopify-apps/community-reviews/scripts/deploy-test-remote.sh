#!/bin/sh
set -eu
cd /opt/decoadmin/staging
test -f /opt/decoadmin/staging/.env.staging
release_dir=/opt/decoadmin/staging/.releases/community-reviews-20260904
compose() { docker compose --env-file /opt/decoadmin/staging/.env.staging -f compose.production.yaml -p decoadmin-staging "$@"; }
case "${1:-}" in
build)
    cp -p .dockerignore "$release_dir/dockerignore-before"
    compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' > "$release_dir/database-before.sql"
    gzip "$release_dir/database-before.sql"
    test -s "$release_dir/database-before.sql.gz"
    tar -xzf "$release_dir/test-release.tar.gz" -C /opt/decoadmin/staging
    export APP_IMAGE=decoadmin-app:community-reviews-20260904
    export NGINX_IMAGE=decoadmin-nginx:community-reviews-20260904
    compose build app nginx
    ;;
activate)
    compose run --rm --no-deps app php artisan config:clear
    compose run --rm --no-deps setup
    compose up -d --no-deps app nginx horizon scheduler
    compose exec -T app php artisan route:list --path=community-reviews
    ;;
*) exit 2 ;;
esac
