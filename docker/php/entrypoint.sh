#!/bin/sh
set -eu

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-}" = "/usr/local/bin/setup-app" ]; then
    if [ ! -f public/build/manifest.json ]; then
        mkdir -p public/build
        cp -a /opt/app-build/. public/build/
    fi

    if [ ! -f .env ] && [ -z "${APP_KEY:-}" ]; then
        cp .env.example .env
    fi

    if [ -f .env ] && ! grep -Eq '^APP_KEY=base64:.+' .env; then
        php artisan key:generate --force --no-interaction
    fi

    if [ ! -f .env ] && [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is required when deploying without a mounted .env file." >&2
        exit 1
    fi

    if [ ! -e public/storage ] && [ ! -L public/storage ]; then
        php artisan storage:link --no-interaction
    fi
    # bootstrap/cache is a persistent deployment volume, so refresh the package
    # manifest before optimizing when a release adds or removes a Laravel package.
    php artisan package:discover --ansi --no-interaction
    php artisan migrate --force --no-interaction

    if [ "${APP_ENV:-}" = "staging" ] || [ "${APP_ENV:-}" = "production" ]; then
        php artisan optimize --no-interaction
    fi

    exit 0
fi

exec "$@"
