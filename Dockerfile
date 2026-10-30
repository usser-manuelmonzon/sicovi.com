# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl gettext-base nginx supervisor \
    libpq-dev libonig-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pdo_pgsql pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sicovi.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-sicovi.conf
RUN php -r 'foreach (["bcmath","pdo_pgsql","pgsql","mbstring","xml","dom","intl","zip","openssl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension.PHP_EOL); exit(1); } }'
WORKDIR /var/www/html

FROM php-base AS dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php-base AS production
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=10000
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=dependencies /var/www/html/bootstrap/cache/packages.php ./bootstrap/cache/packages.php
COPY --from=dependencies /var/www/html/bootstrap/cache/services.php ./bootstrap/cache/services.php
COPY --from=frontend /app/public/build ./public/build
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache \
    && chmod 755 docker/entrypoint.sh
USER www-data
EXPOSE 10000
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --output /dev/null "http://127.0.0.1:${PORT:-10000}/up" || exit 1
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["web"]
