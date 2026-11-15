# NV oOS Laravel Spike — FrankenPHP/Octane dev image
#
# The application itself is bind-mounted at /app and bootstrapped by
# entrypoint.sh from docker/laravel/app-skeleton. This image only provides
# the PHP runtime, extensions, and Composer.

FROM dunglas/frankenphp:1-php8.3

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    pdo_mysql \
    pdo_sqlite \
    intl \
    zip \
    opcache \
    redis \
    pcntl \
    fileinfo

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
