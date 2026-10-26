#!/bin/sh
set -e

echo "[entrypoint] Running Doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[entrypoint] Creating Messenger queue tables (no-op when present)..."
php bin/console messenger:setup-transports --no-interaction

echo "[entrypoint] Clearing cache..."
php bin/console cache:clear --env="${APP_ENV:-prod}" --no-warmup
php bin/console cache:warmup --env="${APP_ENV:-prod}"
chown -R www-data:www-data var public/uploads 2>/dev/null || true

echo "[entrypoint] Starting Apache..."
exec "$@"
