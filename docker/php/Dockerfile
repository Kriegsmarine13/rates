FROM php:8.4.26-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libicu-dev libonig-dev libpq-dev libsqlite3-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath curl intl mbstring opcache pcntl pdo_pgsql pdo_sqlite zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html