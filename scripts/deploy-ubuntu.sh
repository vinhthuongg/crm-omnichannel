#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/crm-omnichannel}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"

cd "$APP_DIR"

echo "==> Installing PHP dependencies"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

echo "==> Installing frontend dependencies"
if [ -f package-lock.json ] || [ -f npm-shrinkwrap.json ]; then
    $NPM_BIN ci
else
    $NPM_BIN install
fi

echo "==> Building frontend assets"
$NPM_BIN run build

echo "==> Running database migrations"
$PHP_BIN artisan migrate --force

echo "==> Linking storage"
$PHP_BIN artisan storage:link || true

echo "==> Refreshing Laravel caches"
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache

echo "==> Restarting queue workers"
$PHP_BIN artisan queue:restart

if command -v supervisorctl >/dev/null 2>&1; then
    echo "==> Restarting Supervisor programs"
    sudo supervisorctl restart crm-reverb:* || true
    sudo supervisorctl restart crm-queue:* || true
fi

echo "==> Fixing writable permissions"
sudo chown -R www-data:www-data storage bootstrap/cache public/storage || true

echo "Deploy complete."
