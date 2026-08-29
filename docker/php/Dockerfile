# Laravel 13 требует PHP 8.3–8.5. Берём 8.4: он в середине поддерживаемого
# диапазона и получает исправления безопасности дольше, чем 8.3.
FROM php:8.4-fpm-alpine

# linux-headers нужен для сборки Xdebug на Alpine.
# Версия Xdebug не закрепляется: pecl ставит последнюю стабильную,
# которая заведомо поддерживает установленный PHP. Если понадобится
# зафиксировать — замените на `pecl install xdebug-3.5.0`.
#
# Xdebug ставится ВСЕГДА и включается только переменной XDEBUG_MODE.
# Образ, где расширения нет, — это дефект №8: `make debug-on` тогда
# перезапускает контейнер, а отладчик всё равно не появляется.
#
# pdo_sqlite нужен набору тестов Feature: он ходит в SQLite в памяти.
# В официальном образе php он, скорее всего, уже собран — но «скорее всего»
# это ровно тот способ рассуждать, которым был получен дефект №1. Поэтому
# проверяем и доставляем, если его нет (повторная установка встроенного
# расширения ломает сборку, отсюда условие), а в конце убеждаемся, что он есть.
RUN apk add --no-cache \
        git curl libzip-dev icu-dev oniguruma-dev libxml2-dev linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring zip intl bcmath opcache \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && if ! php -m | grep -qi '^pdo_sqlite$'; then \
           apk add --no-cache --virtual .sqlite-deps sqlite-dev; \
           docker-php-ext-install -j"$(nproc)" pdo_sqlite; \
           apk del .sqlite-deps; \
       fi \
    && php -m | grep -qi '^pdo_sqlite$' \
    && php -m | grep -qi '^xdebug$' \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

# Имя начинается с zz-, чтобы файл читался после docker-php-ext-xdebug.ini,
# который создаёт docker-php-ext-enable.
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/backend

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
