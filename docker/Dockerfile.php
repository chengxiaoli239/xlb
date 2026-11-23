FROM php:8.4-cli AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY apps/php-api/composer.json apps/php-api/composer.lock ./
RUN set -eu; \
    attempt=1; \
    while [ "$attempt" -le 3 ]; do \
        status=0; \
        composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader \
            > /tmp/composer-install.log 2>&1 || status=$?; \
        cat /tmp/composer-install.log; \
        if [ "$status" -eq 0 ]; then \
            rm -f /tmp/composer-install.log; \
            exit 0; \
        fi; \
        if [ "$attempt" -eq 3 ] || ! grep -Eq 'HTTP/(1\.[01]|2) (502|503|504)' /tmp/composer-install.log; then \
            exit "$status"; \
        fi; \
        sleep "$((attempt * 3))"; \
        attempt=$((attempt + 1)); \
    done

FROM php-base AS runtime
WORKDIR /app
COPY apps/php-api/ ./
COPY --from=vendor /app/vendor ./vendor
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
