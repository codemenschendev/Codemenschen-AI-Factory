FROM php:8.5-cli-alpine

RUN apk add --no-cache postgresql-dev icu-dev linux-headers \
  && docker-php-ext-install pdo_pgsql intl pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize && php artisan config:clear

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
