FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/httpdocs

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx supervisor git unzip zip curl ca-certificates \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libxml2-dev libpq-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer

COPY nginx.conf /etc/nginx/sites-enabled/default

COPY docker/supervisord.dev.conf \
    /etc/supervisor/conf.d/supervisord.conf

COPY docker/php.dev.ini \
    /usr/local/etc/php/conf.d/99-dev.ini

COPY docker/entrypoint.dev.sh \
    /usr/local/bin/entrypoint.dev.sh

RUN chmod +x /usr/local/bin/entrypoint.dev.sh \
    && mkdir -p /run/php /tmp/composer \
    && chown -R www-data:www-data \
        /run/php \
        /tmp/composer \
        /var/www/httpdocs

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.dev.sh"]

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]