#!/bin/sh
set -e

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

php artisan migrate --force

exec php-fpm
