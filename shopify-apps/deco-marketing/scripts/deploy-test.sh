#!/bin/sh
set -eu
cd /opt/decoadmin/staging
release=/opt/decoadmin/staging/.releases/marketing-20260909
case "$(pwd)" in /opt/decoadmin/staging) ;; *) exit 1;; esac
test -f /opt/decoadmin/staging/.env.staging
compose() { APP_ENV_FILE=/opt/decoadmin/staging/.env.staging docker compose --env-file /opt/decoadmin/staging/.env.staging -p decoadmin-staging -f /opt/decoadmin/staging/compose.production.yaml "$@"; }
case "${1:-}" in
prepare)
    umask 077
    mkdir -p "$release"
    test ! -f "$release/previous-sources.tar.gz"
    test "$(sha256sum composer.json | cut -d' ' -f1)" = a24149becc2a12fa4704adec9a6a68c7ee592a322b08b5edae14390150078d4e
    test "$(sha256sum bootstrap/providers.php | cut -d' ' -f1)" = 6b7bf97da09f7a5785b46d415eacead52ce708a4b5831d56ddd9570b9426a89e
    test "$(sha256sum resources/js/app.ts | cut -d' ' -f1)" = 13ea7cf78519d41fedf34619d1cf3a5bb7b0691f53396903a1684b3ad8c16aa1
    tar -czf "$release/previous-sources.tar.gz" composer.json bootstrap/providers.php resources/js/app.ts resources/js/config/menu.ts
    cp -p .env.staging "$release/environment-before"
    compose ps --format '{{.Service}} {{.Image}}' > "$release/images-before.txt"
    tar -xzf "$release/module.tar.gz" -C /opt/decoadmin/staging
    python3 - <<'PY'
from pathlib import Path
p=Path('/opt/decoadmin/staging/resources/js/config/menu.ts')
s=p.read_text()
if "route: '/marketing'" not in s:
    needle="{ name: 'EDM 邮件',"
    assert s.count(needle)==1
    s=s.replace(needle,"{ name: '营销自动化', route: '/marketing', icon: 'campaign', permission: 'marketing.view' },\n            "+needle)
    p.write_text(s)
PY
    ;;
build)
    APP_IMAGE=decoadmin-app:marketing-20260909-r2-staging NGINX_IMAGE=decoadmin-nginx:marketing-20260909-r2-staging compose build app nginx
    ;;
activate)
    export APP_IMAGE=decoadmin-app:marketing-20260909-r2-staging
    export NGINX_IMAGE=decoadmin-nginx:marketing-20260909-r2-staging
    compose run --rm --no-deps app php artisan config:clear
    compose run --rm --no-deps app php artisan route:clear
    compose run --rm --no-deps app php artisan migrate --force --path=shopify-apps/deco-marketing/database/migrations
    python3 - <<'PYENV'
from pathlib import Path
p=Path('/opt/decoadmin/staging/.env.staging')
s=p.read_text()
assert 'testadmin.decomkt.com' in s
updates={'APP_IMAGE':'decoadmin-app:marketing-20260909-r2-staging','NGINX_IMAGE':'decoadmin-nginx:marketing-20260909-r2-staging'}
lines=[line for line in s.splitlines() if line.split('=',1)[0] not in updates]
p.write_text('\n'.join(lines+[k+'='+v for k,v in updates.items()])+'\n')
p.chmod(0o600)
PYENV
    compose up -d --no-deps app nginx horizon scheduler
    compose exec -T app php artisan route:list --path=marketing --except-vendor
    ;;
*) exit 2;;
esac
