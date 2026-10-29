# PII Radar application: Symfony 7.4 on PHP 8.4, served by FrankenPHP.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
        intl \
        opcache \
        pdo_sqlite \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=:8080 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var \
    && chown -R www-data:www-data var

EXPOSE 8080
