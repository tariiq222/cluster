# syntax=docker/dockerfile:1.7

FROM docker.io/library/composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer

FROM docker.io/library/php:8.4-fpm-alpine3.21@sha256:4b92286680751b9639995652cc21d7bd6cbff69a9f29d68827ec42313a74ad3c AS php-base

ARG APP_UID=10001
ARG APP_GID=10001

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    apk add --no-cache icu-libs libzip oniguruma; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" bcmath intl mbstring opcache pcntl pdo_mysql; \
    apk del .build-deps; \
    addgroup -S -g "$APP_GID" app; \
    adduser -S -D -H -u "$APP_UID" -G app app

COPY docker/php-production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

FROM php-base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache git unzip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --classmap-authoritative

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    php artisan package:discover --ansi; \
    composer check-platform-reqs --no-dev

FROM php-base AS production

WORKDIR /var/www/html

COPY --from=vendor --chown=app:app /var/www/html/app ./app
COPY --from=vendor --chown=app:app /var/www/html/bootstrap ./bootstrap
COPY --from=vendor --chown=app:app /var/www/html/config ./config
COPY --from=vendor --chown=app:app /var/www/html/database ./database
COPY --from=vendor --chown=app:app /var/www/html/Modules ./Modules
COPY --from=vendor --chown=app:app /var/www/html/public ./public
COPY --from=vendor --chown=app:app /var/www/html/resources ./resources
COPY --from=vendor --chown=app:app /var/www/html/routes ./routes
COPY --from=vendor --chown=app:app /var/www/html/Shared ./Shared
COPY --from=vendor --chown=app:app /var/www/html/storage ./storage
COPY --from=vendor --chown=app:app /var/www/html/vendor ./vendor
COPY --from=vendor --chown=app:app /var/www/html/artisan ./artisan
COPY --from=vendor --chown=app:app /var/www/html/composer.json ./composer.json
COPY --chown=app:app docker/runtime-entrypoint.sh /usr/local/bin/runtime-entrypoint
COPY --chown=app:app docker/worker-loop.sh /usr/local/bin/worker-loop
COPY --chown=app:app docker/scheduler-loop.sh /usr/local/bin/scheduler-loop

RUN chmod 0555 /usr/local/bin/runtime-entrypoint /usr/local/bin/worker-loop /usr/local/bin/scheduler-loop; \
    find storage bootstrap/cache -type d -exec chmod 0770 {} +; \
    find storage bootstrap/cache -type f -exec chmod 0660 {} +

USER app:app

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/runtime-entrypoint"]
HEALTHCHECK --interval=15s --timeout=3s --retries=5 --start-period=15s CMD ["php", "-r", "$socket=@fsockopen('127.0.0.1',9000); exit($socket ? 0 : 1);"]
CMD ["php-fpm", "-F"]
