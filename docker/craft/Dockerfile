# oOS Engine — Craft CMS Integration Development Image
#
# PHP 8.2 CLI with all extensions Craft CMS needs:
# pdo_mysql, gd, intl, mbstring, zip, imagick, redis, opcache.
# Includes Composer. The actual application is bootstrapped at
# runtime via the entrypoint script or docker/setup.sh.

FROM php:8.2-cli-alpine

# ── OS dependencies ──────────────────────────
RUN apk add --no-cache \
    bash git curl wget zip unzip \
    mysql-client \
    freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
    imagemagick imagemagick-dev \
    autoconf gcc g++ make \
    icu-dev libzip-dev \
    libxml2-dev oniguruma-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j "$(nproc)" \
    pdo_mysql gd intl mbstring zip opcache bcmath exif soap

# ── Imagick ──────────────────────────────────
RUN git clone https://github.com/Imagick/imagick --depth 1 /tmp/imagick \
    && cd /tmp/imagick \
    && phpize && ./configure && make -j "$(nproc)" && make install \
    && docker-php-ext-enable imagick \
    && rm -rf /tmp/imagick

# ── Redis ────────────────────────────────────
RUN pecl install redis && docker-php-ext-enable redis

# ── Composer ─────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Workspace ────────────────────────────────
RUN mkdir -p /workspace/oos
RUN mkdir -p /var/www/html
WORKDIR /var/www/html

EXPOSE 8080

COPY docker/craft/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
