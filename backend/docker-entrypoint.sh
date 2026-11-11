#!/bin/sh
set -e

# Run database migrations if PostgreSQL connection is configured
if [ -n "$DATABASE_URL" ] || [ -n "$DATABASE_URL_POOLED" ] || [ -n "$DB_URL" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --isolated || true
fi

# Automatically register Telegram webhook if bot token is configured
if [ -n "$TELEGRAM_BOT_TOKEN" ]; then
    echo "Registering Telegram webhook..."
    php artisan telegram:register-webhook || true
fi

exec "$@"
