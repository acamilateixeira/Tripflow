# syntax=docker/dockerfile:1.7

# ---------- dependências PHP (sem dev) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# A imagem do Composer não tem as extensões da aplicação (sockets, redis, pcntl);
# elas são instaladas e verificadas no estágio de runtime abaixo.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    --ignore-platform-req=ext-sockets --ignore-platform-req=ext-redis --ignore-platform-req=ext-pcntl
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---------- runtime ----------
# FrankenPHP em modo clássico: servidor HTTP + PHP num único binário, sem nginx/fpm.
FROM dunglas/frankenphp:1-php8.3-alpine AS app

RUN install-php-extensions pdo_mysql redis sockets pcntl opcache intl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php -m | grep -qE '^sockets$' && php -m | grep -qE '^redis$'

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app
COPY --from=vendor /app /app

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=:8000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json

USER www-data
EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8000/api/health || exit 1

# A mesma imagem roda API, worker, relay e consumidor; muda só o comando.
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8000"]
