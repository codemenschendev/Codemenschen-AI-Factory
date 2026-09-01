FROM php:8.5-cli-alpine

# ffmpeg/imagemagick/python3 + DejaVu: tools/make-video.py renders the marketing clips inside
# the horizon container, so the queue worker needs them. DejaVu is the font it draws text with,
# and it is the one font on Alpine with full Vietnamese and German coverage.
RUN apk add --no-cache postgresql-dev icu-dev linux-headers \
      ffmpeg imagemagick python3 font-dejavu \
  && docker-php-ext-install pdo_pgsql intl pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize && php artisan config:clear

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
