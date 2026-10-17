# syntax=docker/dockerfile:1
# Demo Symfony 8 / PHP 8.5 image (linux/arm64 + linux/amd64).
# Built by Kaniko in the Jenkins pipeline (see Jenkinsfile).

########## Stage 1: composer dependencies (no dev, no scripts) ##########
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

########## Stage 2: runtime (Apache + mod_php 8.5) ##########
FROM php:8.5-apache-trixie

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

# pdo_pgsql for the PostgreSQL database.
# Note: Zend OPcache is built into PHP 8.5 and already enabled in the base
# image (no docker-php-ext-install needed — it is no longer a shared ext).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && apt-get purge -y libpq-dev \
 && apt-get autoremove -y \
 && rm -rf /var/lib/apt/lists/*

# Apache: rewrite module + dedicated vhost pointing at public/
RUN a2enmod rewrite
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Optimized autoloader with the full source present; var/ writable for www-data.
RUN composer dump-autoload --optimize --no-interaction \
 && mkdir -p var/cache var/log \
 && chown -R www-data:www-data var

EXPOSE 80
