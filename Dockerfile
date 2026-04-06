FROM php:8.3-cli

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    git \
    curl \
    && docker-php-ext-install zip pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Carpeta de trabajo
WORKDIR /var/www

# Copiar proyecto
COPY . .

# Instalar dependencias Laravel
RUN composer install --optimize-autoloader --no-interaction

# Exponer puerto
EXPOSE 8080

# Ejecutar Laravel
CMD sleep 10 && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080