# syntax=docker/dockerfile:1.7

# ─────────────────────────────────────────────────────────────────────────────
# BexLogs — single Laravel image, four roles
#
#   APP_ROLE=app        Nginx + PHP-FPM (default; fronted by Caddy)
#   APP_ROLE=queue      php artisan queue:work
#   APP_ROLE=scheduler  php artisan schedule:work
#   APP_ROLE=reverb     php artisan reverb:start
#
# Built once, run as four services in docker-compose.production.yml.
#
# Stage graph:
#   vendor    ← composer install (no scripts, no autoloader)
#   frontend  ← needs vendor + PHP so @laravel/vite-plugin-wayfinder can
#               run `php artisan wayfinder:generate` during `npm run build`
#   extension ← zips the browser extension
#   phpdeps   ← composer dump-autoload once public/build is present
#   runtime   ← nginx + php-fpm, copies phpdeps + extension zip
#
# All PHP extensions are installed with mlocati/docker-php-extension-installer
# which handles apk build deps + runtime libs + cleanup in one go. Way more
# robust than upstream docker-php-ext-install (which has a parallel-build
# race that hits `install-modules` when -j > 1).
# ─────────────────────────────────────────────────────────────────────────────


# ─── Stage 1: Composer vendor install ────────────────────────────────────────
FROM php:8.5-cli-alpine AS vendor

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache git unzip \
    && install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY laravel/composer.json laravel/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist


# ─── Stage 2: Vite asset build (php + node + vendor + source) ────────────────
FROM php:8.5-cli-alpine AS frontend

ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ARG VITE_APP_NAME=BexLogs

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache nodejs npm git unzip \
    && install-php-extensions intl zip bcmath

WORKDIR /app

COPY laravel/package.json laravel/package-lock.json ./
COPY laravel/.npmrc ./
RUN npm ci --no-audit --no-fund

COPY laravel/ ./
COPY --from=vendor /app/vendor ./vendor

RUN printf '%s\n' \
        "VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}" \
        "VITE_REVERB_HOST=${VITE_REVERB_HOST}" \
        "VITE_REVERB_PORT=${VITE_REVERB_PORT}" \
        "VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}" \
        "VITE_APP_NAME=${VITE_APP_NAME}" \
    > .env \
    && npm run build


# ─── Stage 2b: Browser-extension zip ─────────────────────────────────────────
FROM alpine:3.20 AS extension

RUN apk add --no-cache bash zip
WORKDIR /src
COPY extension/ ./
RUN bash build.sh \
    && mkdir -p /out \
    && cp build/bexlogs-extension.zip /out/bexlogs-extension.zip


# ─── Stage 3: Composer dump-autoload ─────────────────────────────────────────
FROM php:8.5-cli-alpine AS phpdeps

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache git unzip \
    && install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY laravel/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev


# ─── Stage 4: Runtime ────────────────────────────────────────────────────────
FROM php:8.5-fpm-alpine AS runtime

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

ENV APP_ROLE=app \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILESIZE=64M \
    PHP_POST_MAX_SIZE=64M

RUN apk add --no-cache \
        bash curl tini ca-certificates tzdata \
        nginx supervisor \
    && install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl sockets \
    && rm -rf /usr/local/bin/install-php-extensions /var/cache/apk/*

COPY docker/app/php.ini        /usr/local/etc/php/conf.d/zz-bexlogs.ini
COPY docker/app/php-fpm.conf   /usr/local/etc/php-fpm.d/zz-bexlogs.conf
COPY docker/app/nginx.conf     /etc/nginx/nginx.conf
COPY docker/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/app/entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=phpdeps   --chown=www-data:www-data /app /var/www/html
COPY --from=extension --chown=www-data:www-data /out/bexlogs-extension.zip /opt/bexlogs/bexlogs-extension.zip

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache \
        /var/log/supervisor \
        /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
