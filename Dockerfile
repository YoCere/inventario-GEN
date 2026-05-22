FROM php:8.3-cli

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

RUN apt-get update && apt-get install -y \
    unzip libzip-dev libpng-dev libonig-dev \
    libxml2-dev git curl \
    libfreetype6-dev libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install zip pdo_mysql mbstring exif pcntl bcmath gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction


RUN npm install --frozen-lockfile
RUN npm run build

RUN php artisan storage:link || true

EXPOSE 8080

CMD sh -c "\
until php artisan migrate --force; do \
    echo 'Esperando MySQL...'; \
    sleep 5; \
done && \
php artisan optimize:clear && \
php artisan optimize && \
php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"