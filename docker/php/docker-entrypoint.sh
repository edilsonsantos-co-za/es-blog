#!/bin/sh
set -e

echo "[entrypoint] Running Doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[entrypoint] Clearing cache..."
php bin/console cache:warmup --env=prod

echo "[entrypoint] Starting Apache..."
exec "$@"
