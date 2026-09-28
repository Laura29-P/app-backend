FROM php:8.4-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libonig-dev libzip-dev unzip ca-certificates \
    && docker-php-ext-install pdo_pgsql mbstring zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/start.sh /usr/local/bin/start-app
RUN chmod +x /usr/local/bin/start-app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql SESSION_DRIVER=cookie CACHE_STORE=file QUEUE_CONNECTION=sync \
    STRIPE_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt
EXPOSE 10000
CMD ["/usr/local/bin/start-app"]
