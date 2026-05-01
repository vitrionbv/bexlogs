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
# ─────────────────────────────────────────────────────────────────────────────


# ─── Stage 1: Vite asset build ───────────────────────────────────────────────
FROM node:22-alpine AS frontend

WORKDIR /app

# Public-only build-time values. These end up baked into the client JS bundle
# (they're VITE_-prefixed so Vite exposes them) — they are NOT secrets, just
# the WebSocket endpoint coordinates and the app name that the in-browser
# Echo client needs to know to connect.
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ARG VITE_APP_NAME=BexLogs

COPY laravel/package.json laravel/package-lock.json ./
COPY laravel/.npmrc ./
RUN npm ci --no-audit --no-fund

# Need the rest of the source so vite-plugin-wayfinder + tailwindcss can scan
# templates/routes for class names and generate route helpers.
COPY laravel/ ./

# Vite loads VITE_* from .env files at build time. The production .env on
# the host is never copied into the image (.dockerignore excludes it), so
# we synthesise a minimal .env here from the build args.
RUN printf '%s\n' \
        "VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}" \
        "VITE_REVERB_HOST=${VITE_REVERB_HOST}" \
        "VITE_REVERB_PORT=${VITE_REVERB_PORT}" \
        "VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}" \
        "VITE_APP_NAME=${VITE_APP_NAME}" \
    > .env \
    && npm run build


# ─── Stage 1b: Browser-extension zip ─────────────────────────────────────────
# Built here so the host running deploy doesn't need `zip` installed.
FROM alpine:3.20 AS extension

RUN apk add --no-cache bash zip
WORKDIR /src
COPY extension/ ./
RUN bash build.sh \
    && mkdir -p /out \
    && cp build/bexlogs-extension.zip /out/bexlogs-extension.zip


# ─── Stage 2: Composer install ───────────────────────────────────────────────
FROM php:8.5-cli-alpine AS phpdeps

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache --virtual .build-deps \
        autoconf g++ make pkgconf \
        icu-dev libzip-dev postgresql-dev \
    && apk add --no-cache \
        git unzip libpq icu-libs libzip \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl zip bcmath opcache pcntl sockets \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY laravel/composer.json laravel/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

COPY laravel/ ./
COPY --from=frontend /app/public/build ./public/build

# dump-autoload runs the post-autoload-dump scripts (package:discover) by
# default, so this single command finalises both the optimised classmap
# and the Laravel package manifest.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev


# ─── Stage 3: Runtime ────────────────────────────────────────────────────────
FROM php:8.5-fpm-alpine AS runtime

ENV APP_ROLE=app \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILESIZE=64M \
    PHP_POST_MAX_SIZE=64M

RUN apk add --no-cache --virtual .build-deps \
        autoconf g++ make pkgconf \
        icu-dev libzip-dev postgresql-dev \
    && apk add --no-cache \
        bash curl tini ca-certificates tzdata \
        nginx supervisor \
        libpq icu-libs libzip \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl zip bcmath opcache pcntl sockets \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

COPY docker/app/php.ini        /usr/local/etc/php/conf.d/zz-bexlogs.ini
COPY docker/app/php-fpm.conf   /usr/local/etc/php-fpm.d/zz-bexlogs.conf
COPY docker/app/nginx.conf     /etc/nginx/nginx.conf
COPY docker/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/app/entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=phpdeps  --chown=www-data:www-data /app /var/www/html
COPY --from=extension --chown=www-data:www-data /out/bexlogs-extension.zip /opt/bexlogs/bexlogs-extension.zip

# Pre-create the runtime dirs Laravel writes to, then make the whole tree
# owned by www-data. storage/ is mounted as a volume in production, but the
# image still needs the skeleton so the volume picks the perms up on first
# create.
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
