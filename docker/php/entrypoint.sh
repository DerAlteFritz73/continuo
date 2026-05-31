#!/bin/sh
set -e

cd /var/www/continuo

# Warm up the Symfony cache (writes to var/cache/)
php bin/console cache:warmup --no-debug --env="${APP_ENV:-prod}"

# Start php-fpm as a background daemon
php-fpm -D

# Hand off to nginx as PID 1 (foreground)
exec nginx -g 'daemon off;'
