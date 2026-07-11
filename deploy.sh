#!/usr/bin/env bash

set -euo pipefail

APP_ROOT="/home/u262074081/domains/albedoedu.com/public_html/marketingapi"

echo "Deploying Laravel app from: ${APP_ROOT}"
cd "${APP_ROOT}"

php -v
composer install --no-dev --optimize-autoloader

php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=LeadStageSeeder --force

php artisan storage:link || true

chmod -R 775 storage bootstrap/cache

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache

echo "Deployment completed successfully."
