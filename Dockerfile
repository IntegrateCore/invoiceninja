FROM invoiceninja/invoiceninja-debian:latest

# Copy ONLY Laravel-level branding (safe)
COPY resources /var/www/html/resources
COPY config /var/www/html/config

RUN chown -R www-data:www-data /var/www/html \
 && php artisan optimize:clear

