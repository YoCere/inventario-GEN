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

echo "==> Asegurando estructura de storage (para volumen persistente)..."
# Un volumen recién montado en /var/www/storage/app llega vacío: recreamos las
# carpetas de datos y el symlink public/storage para que las imágenes de
# productos se sirvan y se puedan escribir desde el primer arranque.
mkdir -p /var/www/storage/app/public /var/www/storage/app/private /var/www/storage/app/backups
php /var/www/artisan storage:link || true

echo "==> Ajustando permisos de storage..."
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

echo "==> Iniciando servicios (Nginx + PHP-FPM + Scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/app.conf
