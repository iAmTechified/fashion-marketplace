#!/bin/sh
set -e

# Turn on maintenance mode
# php artisan down || true

# Clear caches
php artisan optimize:clear

# Cache configuration, routes, and views
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Run migrations forced
echo "Running migrations..."
php artisan migrate --force

# Turn off maintenance mode
# php artisan up

# Start supervisord
echo "Starting supervisord..."
exec supervisord -c /etc/supervisord.conf
