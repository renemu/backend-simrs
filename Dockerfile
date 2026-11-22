# Stage 1: Install production PHP dependencies only
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# Stage 2: Minimal PHP 8.3 Alpine runtime with PostgreSQL PDO driver
FROM php:8.3-cli-alpine

RUN apk add --no-cache libpq tzdata \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql \
    && apk del .build-deps

ENV TZ=Asia/Jakarta \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p /app/runtime /app/web/assets \
    && chmod +x /app/yii /app/docker-entrypoint.sh

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --retries=5 --start-period=15s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);'

ENTRYPOINT ["/app/docker-entrypoint.sh"]
