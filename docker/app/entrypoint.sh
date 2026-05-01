#!/bin/sh
# BexLogs container entrypoint. One image, four roles (app|queue|scheduler|reverb).
#
# All roles share:
#   - storage/framework directories materialised into the (possibly empty) volume
#   - storage:link
#   - artisan caches rebuilt against the *runtime* .env (not the build-time one,
#     because there isn't one — we don't bake secrets)
#
# Then we exec the role-specific command.
set -eu

cd /var/www/html

ROLE="${APP_ROLE:-app}"

# ── Ensure storage/ skeleton exists in the mounted volume ──────────────────
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    storage/app/private \
    bootstrap/cache

# Permissions only matter for files we just created; chown -R is cheap on a
# small dir tree but skip it if a previous container already did it (the
# named volume survives recreation).
if [ ! -f storage/.perms-set ]; then
    chown -R www-data:www-data storage bootstrap/cache
    touch storage/.perms-set
    chown www-data:www-data storage/.perms-set
fi

# Public storage symlink (idempotent — Laravel does the right thing if it
# already points to ../storage/app/public).
if [ ! -L public/storage ]; then
    php artisan storage:link --quiet 2>/dev/null || true
fi

# Refresh the bundled browser-extension zip into the storage volume. The
# image stage built the zip from /src/extension/, so this runs once per
# container start (cheap copy, ~50 KB) and ensures the volume tracks the
# image after a deploy.
if [ -f /opt/bexlogs/bexlogs-extension.zip ]; then
    install -m 0644 -o www-data -g www-data \
        /opt/bexlogs/bexlogs-extension.zip \
        storage/app/public/bexlogs-extension.zip
fi

# ── Artisan caches ────────────────────────────────────────────────────────
# All four roles benefit from a hot cache, but only do it once per container
# start and never if explicitly disabled.
if [ "${SKIP_ARTISAN_CACHE:-0}" != "1" ]; then
    php artisan config:cache  --quiet || true
    php artisan route:cache   --quiet || true
    php artisan view:cache    --quiet || true
    php artisan event:cache   --quiet || true
fi

# ── Role dispatch ─────────────────────────────────────────────────────────
case "${ROLE}" in
    app)
        # Nginx + PHP-FPM in one container, supervised together.
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;
    queue)
        # Default (single) queue. Restart hourly to recycle memory.
        exec php artisan queue:work \
            --queue="${QUEUE_NAME:-default}" \
            --tries=3 \
            --timeout=600 \
            --memory=512 \
            --sleep=3 \
            --max-time=3600
        ;;
    scheduler)
        # schedule:work runs the dispatcher in the foreground (Laravel 11+),
        # equivalent to a 1-minute cron.
        exec php artisan schedule:work
        ;;
    reverb)
        exec php artisan reverb:start \
            --host=0.0.0.0 \
            --port=8080
        ;;
    horizon)
        # Reserved for the day Horizon shows up in composer.json.
        exec php artisan horizon
        ;;
    *)
        echo "entrypoint: unknown APP_ROLE='${ROLE}' (expected app|queue|scheduler|reverb)" >&2
        exit 64
        ;;
esac
