#!/bin/bash

echo "==> Starting finanzas_api container..."

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/framework/testing storage/logs bootstrap/cache
chmod -R a+rw storage bootstrap/cache

if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
    echo "==> Running migrations..."
    php artisan migrate --force || echo "WARNING: migrate failed, continuing anyway"
fi

echo "==> Linking storage..."
php artisan storage:link || true

echo "==> Clearing and optimizing..."
php artisan optimize:clear || true
php artisan optimize || true

echo "==> Starting FrankenPHP..."
exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
