#!/bin/sh
set -eu

case "$(dpkg --print-architecture)" in
    amd64)
        export SNAPPDF_CHROMIUM_PATH=/usr/bin/google-chrome-stable
        ;;
    arm64)
        export SNAPPDF_CHROMIUM_PATH=/usr/bin/chromium
        ;;
esac

if [ "${1:-}" = 'supervisord' ] || [ "$*" = 'supervisord -c /etc/supervisor/conf.d/supervisord.conf' ]; then
    mkdir -p \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/bootstrap/cache

    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public
    find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public -type f -exec chmod 644 {} \;
    find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public -type d -exec chmod 755 {} \;

    if [ ! -L /var/www/html/public/storage ]; then
        rm -rf /var/www/html/public/storage
        runuser -u www-data -- php artisan storage:link || true
    fi

    if [ "${APP_ENV:-}" = 'production' ]; then
        runuser -u www-data -- php artisan migrate --force
        runuser -u www-data -- php artisan cache:clear || true
        runuser -u www-data -- php artisan ninja:design-update || true
        runuser -u www-data -- php artisan optimize || true

        if [ "$(runuser -u www-data -- php artisan tinker --execute='echo Schema::hasTable("accounts") && !App\Models\Account::query()->exists();')" = "1" ]; then
            echo 'Running first-time initialization...'
            runuser -u www-data -- php artisan db:seed --force

            if [ -n "${IN_USER_EMAIL:-}" ] && [ -n "${IN_PASSWORD:-}" ]; then
                runuser -u www-data -- php artisan ninja:create-account --email "${IN_USER_EMAIL}" --password "${IN_PASSWORD}"
            else
                echo 'Initialization skipped because IN_USER_EMAIL and IN_PASSWORD are not set.'
            fi
        fi

        echo 'Production setup completed.'
    fi
fi

exec "$@"
