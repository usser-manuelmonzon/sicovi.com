#!/bin/sh
set -eu
umask 027
cd /var/www/html

# CLI maintenance is explicit and never starts the HTTP service.
if [ "${1:-web}" != "web" ]; then
    exec "$@"
fi

export PORT="${PORT:-10000}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT debe ser un entero.' >&2; exit 1;; esac
if [ "${#PORT}" -gt 5 ] || [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT debe estar entre 1024 y 65535 para el proceso sin privilegios.' >&2
    exit 1
fi

php docker/validate-environment.php
php artisan config:cache --no-ansi
php artisan route:cache --no-ansi
php artisan view:cache --no-ansi
envsubst '${PORT}' < docker/nginx.conf.template > /tmp/nginx.conf
nginx -t -c /tmp/nginx.conf
exec /usr/bin/supervisord -c /var/www/html/docker/supervisord.conf
