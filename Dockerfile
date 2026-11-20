FROM composer:2.8 AS composer
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libxml2-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_mysql pdo_sqlite curl mbstring dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress
COPY . .
RUN chmod +x docker/start.sh
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["sh", "docker/start.sh"]
