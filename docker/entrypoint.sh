#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ "${RUN_MIGRATIONS}" = "true" ]; then
  if [ ! -f storage/.migrated ]; then
    php artisan key:generate --force >/dev/null 2>&1 || true
    php artisan migrate --force
    php artisan scribe:generate --no-interaction
    cp storage/app/private/scribe/openapi.yaml public/openapi.yaml 2>/dev/null || true
    touch storage/.migrated
  fi
fi

exec "$@"
