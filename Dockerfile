FROM php:8.3-cli

# Node.js para compilar assets
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Dependencias del sistema
RUN apt-get update && apt-get install -y \
    unzip libzip-dev libpng-dev libonig-dev \
    libxml2-dev git curl \
    && docker-php-ext-install zip pdo_mysql mbstring exif pcntl bcmath gd

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Instalar dependencias PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Instalar dependencias JS y compilar
RUN npm install && npm run build

# Storage link (no necesita DB)
RUN php artisan storage:link || true

EXPOSE 8080

CMD php artisan migrate --force && \
    php artisan optimize:clear && \
    php artisan optimize && \
    php artisan serve --host=0.0.0.0 --port=${PORT:-8080}