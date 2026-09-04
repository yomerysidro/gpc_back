# ============================================================
# STAGE 1: COMPOSER - Dependencias PHP
# ============================================================

FROM composer:2.9.5 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer validate --no-check-all || \
    { echo "ERROR: composer.json o composer.lock invalido."; exit 1; }

RUN --mount=type=cache,target=/tmp/cache \
    composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --ignore-platform-reqs \
    --no-scripts


# ============================================================
# STAGE 2: NODE - Compilación de Vite
# ============================================================

FROM node:22-bookworm-slim AS node_build

WORKDIR /app

COPY package.json package-lock.json* ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY . .

RUN --mount=type=cache,target=/root/.npm \
    --mount=type=cache,target=/app/node_modules/.cache \
    npm run build


# ============================================================
# STAGE 3: RUNTIME - PHP 8.3 + NGINX + SUPERVISOR
# ============================================================

FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    DEBIAN_FRONTEND=noninteractive

WORKDIR /var/www/httpdocs


# ============================================================
# CONFIGURACIONES
# ============================================================

COPY nginx.conf /etc/nginx/sites-enabled/default

COPY docker/php.prod.ini \
    /usr/local/etc/php/conf.d/99-production.ini

COPY docker/supervisord.dev.conf \
    /etc/supervisor/conf.d/supervisord.conf


# ============================================================
# PAQUETES Y EXTENSIONES PHP
# ============================================================

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        git \
        unzip \
        zip \
        curl \
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
    && rm -rf /var/lib/apt/lists/*


# ============================================================
# COPIAR APLICACIÓN
# ============================================================

COPY --from=vendor \
    /app/vendor \
    /var/www/httpdocs/vendor

COPY . /var/www/httpdocs

COPY --from=node_build \
    /app/public/build \
    /var/www/httpdocs/public/build


# ============================================================
# PERMISOS LARAVEL
# ============================================================

RUN mkdir -p \
        /run/php \
        /var/www/httpdocs/storage \
        /var/www/httpdocs/bootstrap/cache \
    && chown -R www-data:www-data \
        /var/www/httpdocs \
        /run/php \
    && chmod -R 775 \
        /var/www/httpdocs/storage \
        /var/www/httpdocs/bootstrap/cache


# ============================================================
# PUERTO
# ============================================================

EXPOSE 80


# ============================================================
# INICIAR SUPERVISOR
# ============================================================

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]