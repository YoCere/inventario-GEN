# ─── Stage 1: PHP vendor (Composer) ──────────────────────────────────────────
FROM composer:latest AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --ignore-platform-reqs

# ─── Stage 2: Assets (Node/Vite) ─────────────────────────────────────────────
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci --frozen-lockfile
COPY . .
# powergrid importa su JS desde ../../vendor — necesita vendor disponible al buildear
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ─── Stage 3: Producción (Nginx + PHP-FPM) ───────────────────────────────────
FROM php:8.3-fpm

# System deps + PHP extensions.
# libwebp-dev + --with-webp: CRÍTICO para Shop/ImageProcessor (variantes WebP).
RUN apt-get update && apt-get install -y \
    nginx supervisor \
    unzip libzip-dev libpng-dev libonig-dev \
    libxml2-dev curl libicu-dev \
    libfreetype6-dev libjpeg62-turbo-dev libwebp-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install zip pdo_mysql mbstring exif pcntl bcmath gd intl \
    && rm -rf /var/lib/apt/lists/*

# PHP runtime tuning
RUN { \
    echo 'upload_max_filesize=50M'; \
    echo 'post_max_size=50M'; \
    echo 'memory_limit=2G'; \
    echo 'max_execution_time=120'; \
    } > /usr/local/etc/php/conf.d/runtime-tuning.ini
WORKDIR /var/www

# Copiar código fuente
COPY . .

# Reemplazar con dependencias de producción de los stages anteriores
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=vendor /usr/bin/composer /usr/bin/composer

# Generar autoloader optimizado con código completo disponible
RUN composer dump-autoload --optimize --no-dev --no-scripts

# Permisos y storage link
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && php artisan storage:link || true

# Configuraciones de Nginx y Supervisor
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

# start.sh: espera MySQL → migra → optimiza → arranca supervisor
CMD ["/usr/local/bin/start.sh"]
