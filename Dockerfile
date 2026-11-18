# ============================================================
# STAGE 1: COMPOSER - Dependencias PHP
# ============================================================

FROM composer:2.9.5 AS composer_binary


# ============================================================
# STAGE 2: NODE - Compilación de Vite
# ============================================================

FROM node:22-bookworm-slim AS node_build

WORKDIR /app

COPY package.json package-lock.json .npmrc ./

RUN npm ci

COPY vite.config.js ./
COPY resources ./resources

RUN npm run build


# ============================================================
# STAGE 3: RUNTIME - PHP 8.3 + NGINX + SUPERVISOR
# ============================================================

FROM php:8.3-fpm-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info

WORKDIR /var/www/httpdocs


# ============================================================
# CONFIGURACIONES
# ============================================================

COPY nginx.conf /etc/nginx/gpc-site.conf

COPY docker/php.prod.ini \
    /usr/local/etc/php/conf.d/99-production.ini

COPY docker/supervisord.dev.conf \
    /etc/supervisor/conf.d/supervisord.conf


# ============================================================
# PAQUETES Y EXTENSIONES PHP
# ============================================================

RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        git \
        unzip \
        zip \
        util-linux \
        ca-certificates \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libpq-dev \
        libzip-dev \
        libicu-dev \
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
        intl \
        opcache \
    && apt-get clean \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && rm -rf /var/lib/apt/lists/*


# ============================================================
# COPIAR APLICACIÓN
# ============================================================

COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer validate --strict \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources ./resources
COPY routes ./routes

COPY --from=node_build \
    /app/public/build \
    /var/www/httpdocs/public/build


# ============================================================
# PERMISOS LARAVEL
# ============================================================

RUN mkdir -p \
        storage/app/private storage/app/public storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-interaction \
    && COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 docker/entrypoint.prod.sh /usr/local/bin/gpc-start


# ============================================================
# PUERTO
# ============================================================

EXPOSE 80


# ============================================================
# INICIAR SUPERVISOR
# ============================================================

STOPSIGNAL SIGTERM
CMD ["/usr/local/bin/gpc-start"]
