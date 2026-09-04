#!/bin/sh

set -e

echo "=========================================="
echo " Iniciando GPC Backend"
echo " PHP 8.3"
echo " Laravel"
echo "=========================================="

mkdir -p /var/www/httpdocs/storage
mkdir -p /var/www/httpdocs/bootstrap/cache

chown -R www-data:www-data \
    /var/www/httpdocs/storage \
    /var/www/httpdocs/bootstrap/cache

chmod -R 775 \
    /var/www/httpdocs/storage \
    /var/www/httpdocs/bootstrap/cache

exec /usr/bin/supervisord \
    -c /etc/supervisor/conf.d/supervisord.conf