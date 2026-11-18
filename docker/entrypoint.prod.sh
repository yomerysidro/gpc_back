#!/bin/sh
set -eu

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

port=${PORT:-80}
case "$port" in
    ''|*[!0-9]*|0*|??????*) fail 'PORT debe ser un entero entre 1 y 65535.' ;;
esac
[ "$port" -le 65535 ] || fail 'PORT fuera del intervalo permitido.'

if [ "${APP_ENV:-production}" = production ]; then
    [ -n "${APP_KEY:-}" ] || fail 'Falta APP_KEY.'
    jwt_secret=${JWT_SECRET:-}
    [ "${#jwt_secret}" -ge 32 ] || fail 'JWT_SECRET debe tener al menos 32 caracteres.'
fi

private_dir=/var/www/httpdocs/storage/app/private
if mountpoint -q "$private_dir"; then
    chown www-data:www-data "$private_dir"
    chmod 0700 "$private_dir"
elif [ -n "${RAILWAY_ENVIRONMENT_ID:-}" ]; then
    fail "Falta el volumen privado en $private_dir."
fi

sed -e "s/listen 80;/listen 0.0.0.0:$port;/" -e 's/listen \[::\]:80;/listen [::]:'"$port"';/' \
    /etc/nginx/gpc-site.conf > /etc/nginx/sites-enabled/default
nginx -t
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
