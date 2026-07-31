#!/bin/sh
set -e

cd /var/www/continuo

# var/cache and var/log are named volumes, so their ownership survives image
# rebuilds and the Dockerfile's chown does not reach them. Anything that ran a
# console command through `docker exec` (which is root by default) leaves
# root-owned directories behind; php-fpm then runs as www-data and cannot write
# its cache, which surfaces as a 500 "Unable to create the cache directory".
mkdir -p var/cache var/log var/share
chown -R www-data:www-data var/cache var/log var/share

# Warm the cache AS www-data, so every file it creates is writable by the
# workers that will later refresh it.
su -s /bin/sh -c "php bin/console cache:warmup --no-debug --env=\"${APP_ENV:-prod}\"" www-data

# Pass Docker env vars through to PHP-FPM worker processes
sed -i 's/;clear_env = no/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf

# Start php-fpm as a background daemon
php-fpm -D

# Hand off to nginx as PID 1 (foreground)
exec nginx -g 'daemon off;'
