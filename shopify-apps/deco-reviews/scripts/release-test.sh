#!/usr/bin/env bash
# Run on the authorized staging host with an exact origin/test commit.
set -euo pipefail
umask 077
review_sha="${1:?Full source SHA required}"
[[ "$review_sha" =~ ^[a-f0-9]{40}$ ]] || exit 2
review_target=/opt/decoadmin/staging
review_repo=/opt/decoadmin/repository
review_source="/opt/decoadmin/releases/test-${review_sha}-source"
review_backup="/opt/decoadmin/backups/deco-reviews-form-${review_sha}"
cd "$review_target"
test -f .env.staging
test -f /opt/decoadmin/secrets/deco-reviews-test.env
git -C "$review_repo" fetch origin test
test "$(git -C "$review_repo" rev-parse origin/test)" = "$review_sha"
test ! -e "$review_source"
test ! -e "$review_backup"
mkdir -p "$review_source" "$review_backup"
git -C "$review_repo" archive "$review_sha" | tar -x -C "$review_source"
cp .env.staging "$review_backup/environment.env"
docker ps --filter label=com.docker.compose.project=decoadmin-production --format '{{.ID}} {{.Image}} {{.Names}}' > "$review_backup/production-before.txt"
docker ps --filter label=com.docker.compose.project=decoadmin-staging --format '{{.ID}} {{.Image}} {{.Names}}' > "$review_backup/staging-before.txt"
docker exec decoadmin-staging-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' | gzip > "$review_backup/database.sql.gz"
gzip -t "$review_backup/database.sql.gz"
cd "$review_source"
export APP_IMAGE="decoadmin-app:deco-reviews-form-${review_sha}"
export NGINX_IMAGE="decoadmin-nginx:deco-reviews-form-${review_sha}"
docker build --label "org.opencontainers.image.revision=$review_sha" -f shopify-apps/deco-reviews/Dockerfile.test --target app-production -t "$APP_IMAGE" .
docker build --label "org.opencontainers.image.revision=$review_sha" -f shopify-apps/deco-reviews/Dockerfile.test --target nginx-production -t "$NGINX_IMAGE" .
cp "$review_target/.env.staging" .env.staging
# Refresh only this new app's credentials and keep sending gates off.
docker run --rm --entrypoint php -v "$review_source:$review_source" -v /opt/decoadmin/secrets/deco-reviews-test.env:/run/reviews.env:ro "$APP_IMAGE" /var/www/html/shopify-apps/deco-reviews/scripts/configure-test-environment.php "$review_source/.env.staging" /run/reviews.env
export APP_ENV_FILE="$review_source/.env.staging"
review_compose=(docker compose --env-file "$APP_ENV_FILE" -f "$review_source/compose.production.yaml" -p decoadmin-staging)
# This release adds only its own form migration; do not run unrelated migrations.
"${review_compose[@]}" run --rm --no-deps -T --interactive=false app php artisan migrate --force --path=shopify-apps/deco-reviews/database/migrations
"${review_compose[@]}" run --rm --no-deps -T --interactive=false app php artisan config:clear
"${review_compose[@]}" run --rm --no-deps -T --interactive=false app php artisan optimize
"${review_compose[@]}" up -d --no-deps app horizon scheduler reverb nginx
for review_attempt in $(seq 1 30); do
    if curl --fail --silent http://127.0.0.1:18080/health; then break; fi
    if test "$review_attempt" = 30; then echo 'Staging health failed; inspect backup and restore prior image tags.' >&2; exit 1; fi
    sleep 2
done
"${review_compose[@]}" exec -T --interactive=false app php artisan horizon:status
docker exec decoadmin-staging-app-1 sha256sum shopify-apps/deco-reviews/backend/Services/FormService.php shopify-apps/deco-reviews/frontend/Pages/DecoReviews/Index.vue
sha256sum shopify-apps/deco-reviews/backend/Services/FormService.php shopify-apps/deco-reviews/frontend/Pages/DecoReviews/Index.vue
docker ps --filter label=com.docker.compose.project=decoadmin-production --format '{{.ID}} {{.Image}} {{.Names}}' > "$review_backup/production-after.txt"
diff -u "$review_backup/production-before.txt" "$review_backup/production-after.txt"
printf '%s\n' "$review_sha" > "$review_backup/source-sha.txt"
printf 'TEST RELEASE VERIFIED: %s\nBACKUP: %s\n' "$review_sha" "$review_backup"
