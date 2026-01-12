#!/bin/sh
set -e

# Turn on maintenance mode
# php artisan down || true

# Clear caches manually first to avoid booting app dependencies before migration
echo "Clearing bootstrap cache..."
rm -f bootstrap/cache/*.php

# Run migrations forced
echo "Running migrations..."
php artisan migrate --force

# Seed database if env var is set
if [ "$SEED_ON_DEPLOY" = "true" ]; then
    echo "Seeding database..."
    php artisan db:seed --force
fi

# Clear and Cache configuration, routes, and views
echo "Optimizing application..."
php artisan optimize:clear
php artisan optimize

# Start supervisord
echo "Starting supervisord..."
exec supervisord -c /etc/supervisord.conf
