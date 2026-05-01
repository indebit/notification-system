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
    touch storage/.migrated
  fi

  # Remove legacy Scribe static docs path so `/docs` resolves to Laravel route.
  rm -rf public/docs

  if [ ! -f public/scribe/openapi.yaml ]; then
    php artisan scribe:generate --no-interaction
  fi
fi

exec "$@"
