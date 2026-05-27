#!/bin/sh
set -e

echo "==> Esperando MySQL y corriendo migraciones..."
until php /var/www/artisan migrate --force; do
    echo "    MySQL no disponible, reintentando en 5s..."
    sleep 5
done

echo "==> Optimizando caches..."
php /var/www/artisan optimize:clear
php /var/www/artisan optimize

echo "==> Iniciando servicios (Nginx + PHP-FPM + Scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/app.conf
