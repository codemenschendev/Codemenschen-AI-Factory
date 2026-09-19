FROM php:8.5-cli-alpine

# ffmpeg/imagemagick/python3 + DejaVu: tools/make-video.py renders the marketing clips inside
# the horizon container, so the queue worker needs them. DejaVu is the font it draws text with,
# and it is the one font on Alpine with full Vietnamese and German coverage.
# chromium: screenshots of the customer's real site, used as ad backgrounds. A picture of the
# actual product beats a generated stock photo and costs no API call.
# nodejs: tools/qa-page.cjs drives the chromium above through playwright-core and reports what
# is wrong with a generated page. playwright-core, not playwright, so no second browser is
# downloaded and there is one to keep patched instead of two.
RUN apk add --no-cache postgresql-dev icu-dev linux-headers \
      ffmpeg imagemagick python3 font-dejavu chromium nodejs npm \
  && docker-php-ext-install pdo_pgsql intl pcntl bcmath

# PHP's own 2 MB upload ceiling turned away a phone photo, and the prototype form takes up to
# four pictures of the business (PrototypeController::MAX_UPLOADS, 8 MB each).
RUN printf 'upload_max_filesize=10M\npost_max_size=40M\nmax_file_uploads=8\n' > "$PHP_INI_DIR/conf.d/uploads.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts

COPY tools/package.json tools/package-lock.json ./tools/
RUN npm --prefix tools ci --omit=dev

COPY . .
RUN composer dump-autoload --optimize && php artisan config:clear

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
