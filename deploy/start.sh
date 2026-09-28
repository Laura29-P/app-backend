#!/bin/sh
set -eu

: "${APP_KEY:?Configura APP_KEY en las variables de Render}"
: "${DB_URL:?Configura DB_URL con la Internal Database URL de Postgres}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
export FRONTEND_URL="${FRONTEND_URL:-$APP_URL}"
: "${APP_URL:?Configura APP_URL con la URL HTTPS del servicio}"
PORT="${PORT:-10000}"
case "$PORT" in *[!0-9]*|'') echo 'PORT debe ser numerico' >&2; exit 1;; esac
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:10000>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan migrate --force
exec apache2-foreground
