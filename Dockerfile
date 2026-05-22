FROM php:8.3-cli

# Node 20 (npm + vite build)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# System deps + PHP extensions.
# libwebp-dev + --with-webp es CRÍTICO para el módulo Shop: ImageProcessor
# genera variantes WebP (thumb/card/full) al subir imágenes de productos.
# Sin el flag, intervention/image lanza "Encoder not supported" en runtime.
RUN apt-get update && apt-get install -y \
    unzip libzip-dev libpng-dev libonig-dev \
    libxml2-dev git curl \
    libfreetype6-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install zip pdo_mysql mbstring exif pcntl bcmath gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP runtime tuning para el módulo Shop:
# - upload_max_filesize/post_max_size 50M: permite galería múltiple sin que
#   PHP rechace requests grandes (cada imagen original puede llegar a 8MB).
# - memory_limit 512M: intervention/image carga la imagen completa para
#   redimensionar; con galería grande puede pasar de 128M.
# - max_execution_time 120s: procesamiento de 3 variantes WebP × 10 imágenes
#   tarda en CPUs lentas.
RUN { \
    echo 'upload_max_filesize=50M'; \
    echo 'post_max_size=50M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=120'; \
    } > /usr/local/etc/php/conf.d/runtime-tuning.ini

WORKDIR /var/www

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN npm install --frozen-lockfile
RUN npm run build

RUN php artisan storage:link || true

EXPOSE 8080

# Startup: espera MySQL, corre migraciones, optimiza caches, arranca el
# scheduler en background (cada 60s — necesario para
# shop:cancel-expired-reservations y otros jobs programados) y sirve la app.
#
# El scheduler corre dentro del mismo contenedor en un loop sleep+exec
# porque no tenemos cron del sistema; suficiente para una instancia única
# (single-server). En cluster, mover scheduler a un sidecar dedicado.
CMD sh -c "\
until php artisan migrate --force; do \
    echo 'Esperando MySQL...'; \
    sleep 5; \
done && \
php artisan optimize:clear && \
php artisan optimize && \
(while true; do php artisan schedule:run --no-interaction >> /tmp/schedule.log 2>&1; sleep 60; done) & \
php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"