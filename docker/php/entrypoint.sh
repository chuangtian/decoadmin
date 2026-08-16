#!/bin/sh
set -eu

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-}" = "/usr/local/bin/setup-app" ]; then
    if [ ! -f public/build/manifest.json ]; then
        mkdir -p public/build
        cp -a /opt/app-build/. public/build/
    fi

    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if ! grep -Eq '^APP_KEY=base64:.+' .env; then
        php artisan key:generate --force --no-interaction
    fi

    php artisan migrate --force --no-interaction
    exit 0
fi

exec "$@"
