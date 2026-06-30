# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-bookworm

ENV APP_HOME=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1 \
    DEBIAN_FRONTEND=noninteractive

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libfcgi-bin \
        mariadb-client \
        nginx \
        supervisor \
        unzip \
        fonts-noto-cjk-extra \
        fonts-wqy-microhei \
        fonts-wqy-zenhei \
        xfonts-wqy \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        bcmath \
        curl \
        exif \
        gd \
        imagick \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        soap \
        zip

RUN if [ "$(dpkg --print-architecture)" = "amd64" ]; then \
        mkdir -p /etc/apt/keyrings && \
        curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /etc/apt/keyrings/google.gpg && \
        echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list && \
        apt-get update && \
        apt-get install -y --no-install-recommends google-chrome-stable && \
        rm -rf /var/lib/apt/lists/*; \
    else \
        apt-get update && \
        apt-get install -y --no-install-recommends chromium && \
        rm -rf /var/lib/apt/lists/*; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/99-invoiceninja.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-invoiceninja.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY preload.php /var/www/html/preload.php

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/.config /var/www/html/storage /var/www/html/bootstrap/cache \
    && rm -f /etc/nginx/sites-enabled/default \
    && chown -R www-data:www-data /var/www \
    && sed -i 's/^listen = 9000/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-invoiceninja.conf

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist \
        --no-scripts

COPY . .

RUN mkdir -p /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public -type d -exec chmod 755 {} \; \
    && find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public -type f -exec chmod 644 {} \;

HEALTHCHECK --start-period=90s CMD curl -fsS http://127.0.0.1/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
