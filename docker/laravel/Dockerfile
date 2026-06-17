# oOS Engine — Laravel Integration Development Image
#
# PHP 8.2 CLI with extensions Laravel needs: pdo_mysql, intl, redis, opcache.
# Includes Composer. The actual application and oOS packages are set up
# at container runtime by docker/setup.sh — this image just provides the
# PHP runtime.

FROM php:8.2-cli-alpine

# ── OS dependencies ──────────────────────────
RUN apk add --no-cache \
    bash git curl zip unzip \
    mysql-client \
    icu-dev libzip-dev

RUN docker-php-ext-install -j "$(nproc)" \
    pdo_mysql intl zip opcache

# ── Redis extension ──────────────────────────
RUN apk add --no-cache --virtual .tmp autoconf gcc g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .tmp

# ── Composer ─────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Workspace ────────────────────────────────
RUN mkdir -p /workspace/oos
RUN mkdir -p /var/www/html
WORKDIR /var/www/html

EXPOSE 8000

# Start script runs composer install (with path repos now mounted)
# then boots Laravel's dev server.
COPY docker/laravel/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
